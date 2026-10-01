<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Actions;

use App\Modules\Accounts\Enums\WardRelationship;
use App\Modules\Accounts\Exceptions\WardLimitReached;
use App\Modules\Accounts\Exceptions\WardNotSavable;
use App\Modules\Accounts\Models\User;
use App\Modules\Accounts\Models\UserWard;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use Illuminate\Support\Facades\DB;

/**
 * Save a ward to a citizen's account (HW-E30-F02-T01, docs/12 §12).
 *
 * Every rule here is also a database constraint, and that duplication is the
 * point rather than an oversight. The trigger is what makes the rule true of
 * the data whatever code runs; this class is what makes the refusal legible to
 * the person who hit it. A `QueryException` reaching a citizen as "something
 * went wrong" would be a cap that works and an interface that does not explain
 * itself.
 *
 * Checked here and in the database:
 *   - the unit is a current ward, not a municipality or a closed one
 *   - at most five saved wards
 *   - at most three added in any 30 days (D-012 §12.4 rule 3)
 *   - one primary ward per account
 *
 * NOT checked, deliberately: whether the ward's municipality is onboarded. A
 * ward can be saved before Hamro Ward opens there, and it starts working on its
 * own when the tenant goes live (§12.2). Refusing would tell a citizen their
 * ward does not exist, when what is true is that we have not got to it yet.
 */
final class SaveUserWard
{
    public const MAX_SAVED = 5;

    public const MAX_PER_30_DAYS = 3;

    /**
     * @throws WardNotSavable
     * @throws WardLimitReached
     */
    public function handle(
        User $user,
        string $wardId,
        WardRelationship $relationship,
        bool $makePrimary = false,
    ): UserWard {
        $ward = AdminUnit::query()->whereKey($wardId)->first();

        if ($ward === null || $ward->level !== AdminLevel::Ward) {
            throw WardNotSavable::notAWard();
        }

        if ($ward->valid_to !== null) {
            throw WardNotSavable::closed();
        }

        if ($makePrimary && ! $relationship->canBePrimary()) {
            throw WardNotSavable::cannotBePrimary();
        }

        $this->assertWithinLimits($user, $wardId, $relationship);

        /*
         * One transaction, because making this ward primary means clearing the
         * previous one, and the partial unique index refuses two. Doing it in
         * two statements outside a transaction leaves a window with no primary
         * ward — and, if the second fails, an account that silently lost its
         * home ward.
         */
        return DB::connection((string) config('tenancy.central_connection'))
            ->transaction(function () use ($user, $wardId, $relationship, $makePrimary): UserWard {
                // First saved ward becomes primary on its own when it can be:
                // asking someone to choose a primary out of one is a question
                // with a single answer.
                $isFirst = ! $user->wards()->exists();
                $primary = $makePrimary || ($isFirst && $relationship->canBePrimary());

                if ($primary) {
                    $user->wards()->where('is_primary', true)->update(['is_primary' => false]);
                }

                return $user->wards()->create([
                    'ward_id' => $wardId,
                    'relationship' => $relationship->value,
                    'is_primary' => $primary,
                ]);
            });
    }

    /**
     * @throws WardNotSavable
     * @throws WardLimitReached
     */
    private function assertWithinLimits(User $user, string $wardId, WardRelationship $relationship): void
    {
        $duplicate = $user->wards()
            ->where('ward_id', $wardId)
            ->where('relationship', $relationship->value)
            ->exists();

        if ($duplicate) {
            throw WardNotSavable::alreadySaved();
        }

        if ($user->wards()->count() >= self::MAX_SAVED) {
            throw WardLimitReached::tooManySaved(self::MAX_SAVED);
        }

        /*
         * A rolling 30 days, not a calendar month. The oldest of the recent
         * additions is what the window turns on, so the refusal can say when it
         * clears rather than leaving a citizen to guess (§12.4 rule 3).
         */
        $recent = $user->wards()
            ->where('created_at', '>', now()->subDays(30))
            ->orderBy('created_at')
            ->get();

        if ($recent->count() >= self::MAX_PER_30_DAYS) {
            throw WardLimitReached::tooManyRecently(
                self::MAX_PER_30_DAYS,
                $recent->first()->created_at->addDays(30)->toIso8601String(),
            );
        }
    }
}

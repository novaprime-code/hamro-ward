<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Actions;

use App\Modules\Accounts\Models\User;
use App\Modules\Accounts\Models\UserWard;

/**
 * Forget a saved ward (HW-E30-F02-T01, docs/12 §12.2).
 *
 * Removing a ward never touches reports already submitted. A report is a thing
 * that happened, with a relationship snapshot taken at the time; rewriting that
 * because someone later moved house would falsify the record a moderator was
 * working from.
 *
 * Removing the primary ward leaves the account with NO primary, rather than
 * promoting whichever ward happens to be next. Promotion would be the platform
 * deciding where somebody is from — silently, on the strength of row order —
 * and the account works perfectly well without a primary until they say.
 */
final class RemoveUserWard
{
    public function handle(User $user, string $userWardId): bool
    {
        $saved = $user->wards()->whereKey($userWardId)->first();

        if ($saved === null) {
            return false;
        }

        return (bool) $saved->delete();
    }

    /**
     * Make one saved ward the primary one, clearing whichever was.
     *
     * Transactional for the same reason as saving: the partial unique index
     * allows exactly one primary per account, so the clear and the set have to
     * land together or an account is briefly left with none.
     */
    public function setPrimary(User $user, string $userWardId): ?UserWard
    {
        $saved = $user->wards()->whereKey($userWardId)->first();

        if ($saved === null || ! $saved->relationship->canBePrimary()) {
            return null;
        }

        return $user->getConnection()->transaction(function () use ($user, $saved): UserWard {
            $user->wards()->where('is_primary', true)->update(['is_primary' => false]);
            $saved->forceFill(['is_primary' => true])->save();

            return $saved;
        });
    }
}

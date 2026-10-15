<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Modules\Accounts\Actions\RemoveUserWard;
use App\Modules\Accounts\Actions\SaveUserWard;
use App\Modules\Accounts\Enums\WardRelationship;
use App\Modules\Accounts\Exceptions\WardLimitReached;
use App\Modules\Accounts\Exceptions\WardNotSavable;
use App\Modules\Accounts\Http\Resources\SavedWardResource;
use App\Modules\Accounts\Models\User;
use App\Modules\Accounts\Models\UserWard;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The wards a citizen has saved (HW-E30-F02-T01; docs/09 §5.6, 12 §12).
 *
 * The rules live in the actions and in the database, not here: this is the
 * HTTP edge, so its whole job is to turn a request into an action call and an
 * action's refusal into something a screen can show.
 *
 * A ward is named by **slug path and number** — `koshi/sunsari/koshara` and
 * `4` — rather than by its id. The id is a uuid that means nothing to anyone
 * and that the client would have to look up first; the path is what the
 * picker already holds, what the URL already says, and what a person could
 * read out loud.
 */
final class UserWardsController
{
    public function index(Request $request): JsonResponse
    {
        return $this->list($this->citizen($request));
    }

    public function store(Request $request, SaveUserWard $save): JsonResponse
    {
        $user = $this->citizen($request);

        $validated = $request->validate([
            'slug_path' => ['required', 'string', 'max:200'],
            'ward_number' => ['required', 'integer', 'min:1', 'max:35'],
            'relationship' => ['required', 'string'],
            'make_primary' => ['sometimes', 'boolean'],
        ]);

        $relationship = WardRelationship::tryFrom($validated['relationship']);

        if ($relationship === null) {
            throw ValidationException::withMessages([
                'relationship' => 'Choose one of the relationships offered.',
            ]);
        }

        $ward = $this->wardAt($validated['slug_path'], (int) $validated['ward_number']);

        if ($ward === null) {
            throw ValidationException::withMessages([
                'ward_number' => 'There is no such ward. Check the municipality and the number.',
            ]);
        }

        try {
            $save->handle($user, $ward->id, $relationship, (bool) ($validated['make_primary'] ?? false));
        } catch (WardNotSavable|WardLimitReached $refusal) {
            /*
             * The action's own words, verbatim. They were written to be read
             * by the person who hit the limit — including when the cap
             * clears — and rewording them here would mean maintaining the
             * same explanation in two places.
             */
            throw ValidationException::withMessages(['ward_number' => $refusal->getMessage()]);
        }

        return $this->list($user, 201);
    }

    public function destroy(Request $request, string $savedWard, RemoveUserWard $remove): JsonResponse
    {
        $user = $this->citizen($request);

        abort_unless($remove->handle($user, $savedWard), 404);

        return $this->list($user);
    }

    public function makePrimary(Request $request, string $savedWard, RemoveUserWard $wards): JsonResponse
    {
        $user = $this->citizen($request);
        $saved = $user->wards()->whereKey($savedWard)->first();

        abort_unless($saved instanceof UserWard, 404);

        if ($wards->setPrimary($user, $savedWard) === null) {
            // The only way to get here: a workplace or "other" ward, which
            // cannot be a home ward (WardRelationship::canBePrimary).
            throw ValidationException::withMessages([
                'is_primary' => 'Only a ward you live in can be your main ward.',
            ]);
        }

        return $this->list($user);
    }

    /**
     * The whole list, after every change.
     *
     * Saving one ward can move the primary flag off another, and removing one
     * can leave the account with no primary at all, so a response carrying
     * only the row that changed would leave the screen to guess at the rest.
     */
    private function list(User $user, int $status = 200): JsonResponse
    {
        $wards = $user->wards()
            ->with('ward.parent.parent')
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => SavedWardResource::collection($wards)->resolve(),
            'meta' => [
                'max_saved' => SaveUserWard::MAX_SAVED,
                'max_per_30_days' => SaveUserWard::MAX_PER_30_DAYS,
            ],
        ], $status)->header('Cache-Control', 'private, no-store');
    }

    /** The current ward at `{slug_path}/{number}`, or null. */
    private function wardAt(string $slugPath, int $wardNumber): ?AdminUnit
    {
        $slug = AdminUnitSlug::query()
            ->where('slug_path', trim($slugPath, '/').'/'.$wardNumber)
            ->where('is_current', true)
            ->first();

        if ($slug === null) {
            return null;
        }

        return AdminUnit::query()
            ->where('level', AdminLevel::Ward->value)
            ->whereKey($slug->admin_unit_id)
            ->first();
    }

    /**
     * On the admin host the session belongs to a staff account, which has no
     * saved wards and no business here — the same 404 MeController gives.
     */
    private function citizen(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Staff\Support;

use App\Modules\Staff\Models\StaffUser;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * The staff session's state, in one place (docs/12 §11.5).
 *
 * Signing in has two steps. After the password, the session holds a PENDING
 * sign-in — who, which second step (enrol or challenge), and since when — and
 * nobody is authenticated yet. Only the second step logs the person in. So a
 * password alone, however it was obtained, reaches no staff endpoint.
 */
final class StaffSession
{
    public const STAGE_ENROL = 'enrol';

    public const STAGE_CHALLENGE = 'challenge';

    private const PENDING = 'staff.pending';

    private const SIGNED_IN_AT = 'staff.signed_in_at';

    private const LAST_SEEN_AT = 'staff.last_seen_at';

    public function begin(Session $session, StaffUser $staff, string $stage): void
    {
        $session->put(self::PENDING, ['id' => $staff->id, 'stage' => $stage, 'at' => now()->getTimestamp()]);
    }

    /** The staff member whose sign-in is waiting on this stage, if it has not expired. */
    public function pending(Session $session, string $stage): ?StaffUser
    {
        /** @var array{id: string, stage: string, at: int}|null $pending */
        $pending = $session->get(self::PENDING);

        if ($pending === null || $pending['stage'] !== $stage) {
            return null;
        }

        if (now()->getTimestamp() - $pending['at'] > (int) config('staff.pending_minutes') * 60) {
            $session->forget(self::PENDING);

            return null;
        }

        $staff = StaffUser::query()->find($pending['id']);

        return $staff !== null && $staff->is_active ? $staff : null;
    }

    public function complete(Session $session, StaffUser $staff): void
    {
        $session->forget(self::PENDING);
        $session->regenerate();

        Auth::guard('staff')->login($staff);
        $staff->forceFill(['last_login_at' => now()])->save();

        $session->put(self::SIGNED_IN_AT, now()->getTimestamp());
        $session->put(self::LAST_SEEN_AT, now()->getTimestamp());
    }

    /** False once the session has idled out or reached its absolute limit. */
    public function touch(Session $session): bool
    {
        $now = now()->getTimestamp();
        $signedIn = (int) $session->get(self::SIGNED_IN_AT, 0);
        $lastSeen = (int) $session->get(self::LAST_SEEN_AT, 0);

        if ($now - $lastSeen > (int) config('staff.idle_minutes') * 60
            || $now - $signedIn > (int) config('staff.absolute_hours') * 3600) {
            return false;
        }

        $session->put(self::LAST_SEEN_AT, $now);

        return true;
    }

    public function end(Session $session): void
    {
        Auth::guard('staff')->logout();
        $session->invalidate();
        $session->regenerateToken();
    }
}

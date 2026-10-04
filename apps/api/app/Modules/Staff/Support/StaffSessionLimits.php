<?php

declare(strict_types=1);

namespace App\Modules\Staff\Support;

use Illuminate\Contracts\Session\Session;

/**
 * A staff session ends after 30 idle minutes and, however busy, 12 hours
 * after sign-in (HW-E13-F01-T03). Stamped on every staff sign-in by
 * StampStaffSignIn; checked on every authenticated staff request.
 */
final class StaffSessionLimits
{
    private const SIGNED_IN_AT = 'staff.signed_in_at';

    private const LAST_SEEN_AT = 'staff.last_seen_at';

    public function stamp(Session $session): void
    {
        $session->put(self::SIGNED_IN_AT, now()->getTimestamp());
        $session->put(self::LAST_SEEN_AT, now()->getTimestamp());
    }

    /** True, and the session marked as seen, while both limits hold. */
    public function touch(Session $session): bool
    {
        $now = now()->getTimestamp();

        if ($now - (int) $session->get(self::LAST_SEEN_AT, 0) > (int) config('staff.idle_minutes') * 60
            || $now - (int) $session->get(self::SIGNED_IN_AT, 0) > (int) config('staff.absolute_hours') * 3600) {
            return false;
        }

        $session->put(self::LAST_SEEN_AT, $now);

        return true;
    }
}

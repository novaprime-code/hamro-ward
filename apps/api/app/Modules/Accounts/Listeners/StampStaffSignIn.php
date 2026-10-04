<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Listeners;

use App\Modules\Staff\Models\StaffUser;
use App\Modules\Staff\Support\StaffSessionLimits;
use Illuminate\Auth\Events\Login;

/**
 * Starts the clock on a staff session whenever Fortify signs one in —
 * after the password alone (no two-factor yet) or after the challenge.
 */
final class StampStaffSignIn
{
    public function __construct(private readonly StaffSessionLimits $limits) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof StaffUser) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->save();

        if (app()->bound('session.store') && request()->hasSession()) {
            $this->limits->stamp(request()->session());
        }
    }
}

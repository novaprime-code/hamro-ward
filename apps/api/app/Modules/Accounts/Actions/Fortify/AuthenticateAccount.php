<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Actions\Fortify;

use App\Modules\Accounts\Enums\UserStatus;
use App\Modules\Accounts\Models\User;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Fortify's credential check, for whichever account the host signs in
 * (Fortify::authenticateUsing; docs/12 §11).
 *
 * Returning null is Fortify's "these credentials do not match", identical
 * for an unknown address and a wrong password. Staff add a lockout
 * (HW-E13-F01-T03): five failures on one address lock the account for
 * fifteen minutes, answered with 423 and the time it ends, which the
 * sign-in screen shows.
 */
final class AuthenticateAccount
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function __invoke(Request $request): User|StaffUser|null
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        return config('fortify.guard') === 'staff'
            ? $this->staff($email, $password)
            : $this->citizen($email, $password);
    }

    private function citizen(string $email, string $password): ?User
    {
        $user = User::query()->where('email', $email)->first();

        return $user !== null && $user->status === UserStatus::Active && Hash::check($password, $user->password)
            ? $user
            : null;
    }

    private function staff(string $email, string $password): ?StaffUser
    {
        $staff = StaffUser::query()->where('email', $email)->first();

        if ($staff?->locked_until !== null && $staff->locked_until->isFuture()) {
            throw $this->locked($staff);
        }

        if ($staff === null || ! $staff->is_active || ! Hash::check($password, $staff->password)) {
            $this->failed($email, $staff);

            return null;
        }

        RateLimiter::clear($this->key($email));

        return $staff;
    }

    private function failed(string $email, ?StaffUser $staff): void
    {
        $key = $this->key($email);
        RateLimiter::hit($key, (int) config('staff.lockout_minutes') * 60);

        if ($staff === null || RateLimiter::attempts($key) < (int) config('staff.max_failed_logins')) {
            return;
        }

        RateLimiter::clear($key);
        $staff->forceFill(['locked_until' => now()->addMinutes((int) config('staff.lockout_minutes'))])->save();
        $this->audit->central(ActorType::System, 'staff_user.locked', 'staff_user', $staff->id, ['reason' => 'failed_sign_ins']);

        throw $this->locked($staff);
    }

    private function locked(StaffUser $staff): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => 'Too many failed attempts. This account is locked for now.',
            'locked_until' => $staff->locked_until?->toIso8601String(),
        ], 423));
    }

    private function key(string $email): string
    {
        return 'staff-login-failures:'.hash('sha256', $email);
    }
}

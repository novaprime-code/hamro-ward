<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Controllers;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Staff\Models\StaffMembership;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Staff\Support\StaffSession;
use App\Modules\Staff\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Staff sign-in with mandatory two-factor (docs/12 §11.5, HW-E13-F01-T03).
 *
 *   POST login               password → { next: "enrol" | "challenge" }
 *   POST two-factor/enrol    (first sign-in) → secret as QR + otpauth URL
 *   POST two-factor/confirm  first code → signed in, recovery codes shown once
 *   POST two-factor/challenge code or recovery code → signed in
 *   POST logout, GET me
 *
 * Custom controllers rather than Fortify: the fallback docs/12 §11.2 names
 * for the staff guard, and the whole flow is these six handlers.
 *
 * Every refusal of a password says the same thing whether or not the
 * address exists. Five failures on one address lock the account for fifteen
 * minutes; the response says until when, which the sign-in screen shows.
 */
final class StaffAuthController
{
    public function __construct(
        private readonly StaffSession $staffSession,
        private readonly TwoFactor $twoFactor,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:500'],
        ]);

        $email = mb_strtolower($credentials['email']);
        $staff = StaffUser::query()->where('email', $email)->first();

        if ($staff?->locked_until !== null && $staff->locked_until->isFuture()) {
            return $this->locked($staff);
        }

        if ($staff === null || ! $staff->is_active || ! Hash::check($credentials['password'], $staff->password)) {
            return $this->failed($email, $staff);
        }

        RateLimiter::clear($this->throttleKey($email));

        $stage = $staff->two_factor_confirmed_at === null ? StaffSession::STAGE_ENROL : StaffSession::STAGE_CHALLENGE;
        $this->staffSession->begin($request->session(), $staff, $stage);

        return response()->json(['next' => $stage]);
    }

    public function enrol(Request $request): JsonResponse
    {
        $staff = $this->staffSession->pending($request->session(), StaffSession::STAGE_ENROL);

        if ($staff === null) {
            return $this->noPendingSignIn();
        }

        // A new secret each time this is called, so a QR code that was
        // photographed but never confirmed is worth nothing.
        $secret = $this->twoFactor->newSecret();
        $staff->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();
        $url = $this->twoFactor->otpauthUrl($staff, $secret);

        return response()->json(['otpauth_url' => $url, 'qr_svg' => $this->twoFactor->qrSvg($url), 'secret' => $secret]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'];
        $staff = $this->staffSession->pending($request->session(), StaffSession::STAGE_ENROL);

        if ($staff === null) {
            return $this->noPendingSignIn();
        }

        $secret = (string) $staff->getAttribute('two_factor_secret');

        if ($secret === '' || ! $this->twoFactor->verify($staff, $secret, $code)) {
            return response()->json(['message' => 'That code is not right. Check the time on your phone and try the newest code.'], 422);
        }

        $codes = $this->twoFactor->newRecoveryCodes();
        $staff->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes['stored']])->save();

        $this->audit->central(ActorType::Staff, 'staff_user.two_factor_enrolled', 'staff_user', $staff->id, actorId: $staff->id);
        $this->staffSession->complete($request->session(), $staff);

        return response()->json(['signed_in' => true, 'recovery_codes' => $codes['plain']]);
    }

    public function challenge(Request $request): JsonResponse
    {
        $input = $request->validate([
            'code' => ['nullable', 'string', 'max:20', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:40'],
        ]);
        $staff = $this->staffSession->pending($request->session(), StaffSession::STAGE_CHALLENGE);

        if ($staff === null) {
            return $this->noPendingSignIn();
        }

        $accepted = isset($input['code'])
            ? $this->twoFactor->verify($staff, (string) $staff->getAttribute('two_factor_secret'), (string) $input['code'])
            : $this->twoFactor->consumeRecoveryCode($staff, (string) $input['recovery_code']);

        if (! $accepted) {
            // A wrong second step counts towards the same lockout as a wrong
            // password: otherwise the six digits could be guessed at leisure.
            return $this->failed($staff->email, $staff);
        }

        if (! isset($input['code'])) {
            $this->audit->central(ActorType::Staff, 'staff_user.recovery_code_used', 'staff_user', $staff->id, actorId: $staff->id);
        }

        $this->staffSession->complete($request->session(), $staff);

        return response()->json(['signed_in' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->staffSession->end($request->session());

        return response()->json(['signed_in' => false]);
    }

    public function me(): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = Auth::guard('staff')->user();

        return response()->json(['data' => [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'operator_admin' => $staff->isOperatorAdmin(),
            'memberships' => $staff->memberships()->live()->with('tenant')->get()
                ->map(fn (StaffMembership $membership): array => [
                    'tenant_key' => $membership->tenant->tenant_key,
                    'role' => $membership->role->value,
                ])->values()->all(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    private function failed(string $email, ?StaffUser $staff): JsonResponse
    {
        $key = $this->throttleKey($email);
        RateLimiter::hit($key, (int) config('staff.lockout_minutes') * 60);

        if ($staff !== null && RateLimiter::attempts($key) >= (int) config('staff.max_failed_logins')) {
            RateLimiter::clear($key);
            $staff->forceFill(['locked_until' => now()->addMinutes((int) config('staff.lockout_minutes'))])->save();
            $this->audit->central(ActorType::System, 'staff_user.locked', 'staff_user', $staff->id, ['reason' => 'failed_sign_ins']);

            return $this->locked($staff);
        }

        return response()->json(['message' => 'These details do not match an active staff account.'], 422);
    }

    private function locked(StaffUser $staff): JsonResponse
    {
        return response()->json([
            'message' => 'Too many failed attempts. This account is locked for now.',
            'locked_until' => $staff->locked_until?->toIso8601String(),
        ], 423);
    }

    private function noPendingSignIn(): JsonResponse
    {
        return response()->json(['message' => 'Start again by signing in with your password.'], 409);
    }

    private function throttleKey(string $email): string
    {
        return 'staff-login:'.hash('sha256', mb_strtolower($email));
    }
}

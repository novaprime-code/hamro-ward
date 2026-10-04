<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Middleware;

use App\Modules\Staff\Models\StaffUser;
use App\Modules\Staff\Support\StaffSessionLimits;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `auth.host` — Fortify's auth middleware and the staff API's, resolved per
 * request (docs/12 §11.2, HW-E13-F01-T03).
 *
 * Fortify writes `auth:<guard>` into its routes when they load, which would
 * fix one guard for both hosts. This ignores that parameter and uses the
 * guard ConfigureAuthForHost chose for this host.
 *
 * For staff it also holds the rules no staff request may skip:
 *  - an inactive or locked account is signed out;
 *  - outside the 30-minute idle and 12-hour absolute limits, signed out;
 *  - without confirmed two-factor, only the endpoints that ENROL two-factor
 *    answer; everything else is 403 with `two_factor_required`, which is the
 *    staff screens' cue to start enrolment.
 */
final class AuthenticateForHost extends Authenticate
{
    /** Routes a signed-in member of staff may use before two-factor is confirmed. */
    private const ENROLMENT_ROUTES = [
        'logout',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
    ];

    public function __construct(Auth $auth, private readonly StaffSessionLimits $limits)
    {
        parent::__construct($auth);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle($request, Closure $next, ...$guards): Response
    {
        $guard = (string) config('fortify.guard');

        $this->authenticate($request, [$guard]);

        $user = $this->auth->guard($guard)->user();

        if ($user instanceof StaffUser) {
            if (! $this->usable($user) || ! $this->limits->touch($request->session())) {
                $this->auth->guard($guard)->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw new AuthenticationException('Unauthenticated.', [$guard]);
            }

            if ($user->two_factor_confirmed_at === null && ! in_array($request->route()?->getName(), self::ENROLMENT_ROUTES, true)) {
                return response()->json([
                    'message' => 'Set up two-step verification to continue.',
                    'two_factor_required' => true,
                ], 403);
            }
        }

        return $next($request);
    }

    private function usable(StaffUser $staff): bool
    {
        return $staff->is_active && ($staff->locked_until === null || $staff->locked_until->isPast());
    }
}

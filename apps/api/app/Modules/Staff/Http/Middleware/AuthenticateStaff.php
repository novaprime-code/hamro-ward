<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Middleware;

use App\Modules\Staff\Models\StaffUser;
use App\Modules\Staff\Support\StaffSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate in front of every staff endpoint except signing in
 * (HW-E13-F01-T03).
 *
 * Signed in on the staff guard, active, not locked, two-factor confirmed,
 * and inside both session limits — or 401 and the session is ended. The
 * two-factor check is here as well as in the sign-in flow, so an account
 * whose two-factor was reset mid-session loses access at the next request.
 */
final class AuthenticateStaff
{
    public function __construct(private readonly StaffSession $staffSession) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $staff = Auth::guard('staff')->user();

        $usable = $staff instanceof StaffUser
            && $staff->is_active
            && ($staff->locked_until === null || $staff->locked_until->isPast())
            && $staff->two_factor_confirmed_at !== null
            && $this->staffSession->touch($request->session());

        if (! $usable) {
            // End a session only when someone was signed in to it. A request
            // made mid-sign-in must not throw away the half-finished sign-in.
            if ($staff !== null) {
                $this->staffSession->end($request->session());
            }

            return response()->json(['message' => 'Sign in to continue.'], 401);
        }

        return $next($request);
    }
}

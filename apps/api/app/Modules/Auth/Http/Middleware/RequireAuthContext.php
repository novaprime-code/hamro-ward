<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Enums\AuthContext;
use App\Modules\Auth\Support\AuthHosts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a route to any host it does not belong to (docs/12 §11.2, §16).
 *
 *   Route::middleware('auth.host:staff')     → only the admin host
 *   Route::middleware('auth.host:citizen')   → only the public host
 *
 * **404, not 403.** A 403 on `admin.hamroward.np/api/v1/staff/queue` confirms
 * that the route exists and that the caller merely lacks something; from the
 * public host the staff surface should be indistinguishable from a typo. The
 * same reasoning the tenant routes already use for unknown municipalities
 * (docs/12 §6).
 *
 * This is a second line, not the only one. The staff Fortify routes are
 * registered under Route::domain() so they are not matchable from the public
 * host at all, and the tenant membership check runs separately before any
 * database is switched. This exists for the routes that are declared in
 * routes/api_v1.php by hand, where a group is easy to put a route into and
 * easy to forget to constrain.
 */
final class RequireAuthContext
{
    public function handle(Request $request, Closure $next, string $context): Response
    {
        $required = AuthContext::tryFrom($context);

        if (! $required instanceof AuthContext) {
            /*
             * A typo in the middleware argument must not fail open. 'auth.host:stafff'
             * would otherwise match nothing and allow everything.
             */
            abort(404);
        }

        if (AuthHosts::contextFor($request) !== $required) {
            abort(404);
        }

        return $next($request);
    }
}

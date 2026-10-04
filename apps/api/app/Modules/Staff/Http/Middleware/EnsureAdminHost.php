<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Middleware;

use App\Modules\Staff\Support\AdminHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff API exists only on the admin host. Anywhere else, anything under
 * /api/v1/staff is a 404 — not a 401 or a 419, either of which would confirm
 * there is something there to be refused.
 *
 * Global and path-based on purpose: route middleware runs after the session,
 * CSRF and authentication middleware, any of which would answer first.
 */
final class EnsureAdminHost
{
    public function __construct(private readonly AdminHost $adminHost) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/staff', 'api/v1/staff/*')) {
            abort_unless($this->adminHost->matches($request), 404);
        }

        return $next($request);
    }
}

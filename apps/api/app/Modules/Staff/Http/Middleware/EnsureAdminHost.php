<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Middleware;

use App\Modules\Staff\Support\AdminHost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff routes exist only on the admin host. Anywhere else they are a 404 —
 * not a 403, which would confirm that there is something to be refused.
 */
final class EnsureAdminHost
{
    public function __construct(private readonly AdminHost $adminHost) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->adminHost->matches($request), 404);

        return $next($request);
    }
}

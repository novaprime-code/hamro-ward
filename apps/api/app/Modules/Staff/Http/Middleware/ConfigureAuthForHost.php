<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Middleware;

use App\Modules\Staff\Support\AdminHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who this request can sign in as, decided by its host before any session
 * starts (docs/12 §11.1, §11.2; HW-E30-F01-T01).
 *
 *                     admin host            any other host
 *   session cookie    hw_staff_session      hw_session
 *   fortify.guard     staff                 web
 *   fortify.passwords staff_users           users
 *   sanctum.guard     [staff]               [web]
 *
 * One set of Fortify routes and one Sanctum therefore serve both kinds of
 * account, and a citizen cookie can never be presented as a staff one: the
 * names differ, both are host-only, and each guard reads only its own.
 *
 * Global middleware, appended after TrustProxies so that behind the web tier
 * it sees the forwarded host rather than the proxy's address.
 */
final class ConfigureAuthForHost
{
    public function __construct(
        private readonly AdminHost $adminHost,
        private readonly SessionManager $sessions,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $this->adminHost->matches($request);
        $cookie = (string) config($staff ? 'staff.session_cookie' : 'staff.public_session_cookie');

        config([
            'session.cookie' => $cookie,
            'fortify.guard' => $staff ? 'staff' : 'web',
            'fortify.passwords' => $staff ? 'staff_users' : 'users',
            'sanctum.guard' => [$staff ? 'staff' : 'web'],
        ]);

        // The session store keeps the name it was built with; a long-lived
        // process (Octane, or a test) must have it renamed as well.
        $this->sessions->driver()->setName($cookie);

        return $next($request);
    }
}

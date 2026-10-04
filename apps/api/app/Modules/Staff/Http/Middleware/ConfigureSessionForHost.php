<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Middleware;

use App\Modules\Staff\Support\AdminHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the session cookie from the host, before any session starts
 * (docs/12 §11.1, §11.2).
 *
 * Staff and citizens get differently named, host-only cookies: a citizen
 * session cookie can never be presented to the admin host as a staff one,
 * and signing in on one host never signs anyone out of the other. Global
 * middleware, so it runs ahead of StartSession on every route — including
 * /sanctum/csrf-cookie, whose CSRF token must live in the same session the
 * staff routes will read.
 */
final class ConfigureSessionForHost
{
    public function __construct(
        private readonly AdminHost $adminHost,
        private readonly SessionManager $sessions,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) ($this->adminHost->matches($request)
            ? config('staff.session_cookie')
            : config('staff.public_session_cookie'));

        // The config is what StartSession reads for the cookie; the store
        // caches its own name from when it was built, so a long-lived
        // process (Octane, or a test) must have it renamed too.
        config(['session.cookie' => $name]);
        $this->sessions->driver()->setName($name);

        return $next($request);
    }
}

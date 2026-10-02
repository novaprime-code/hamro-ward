<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\ConfigureAuthForHost;
use App\Modules\Auth\Http\Middleware\RequireAuthContext;
use App\Modules\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api_v1.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tenancy resolution middleware is registered here in HW-E29-F03-T01.

        /*
         * Which account type this request is (docs/12 §11.2, D-018).
         *
         * PREPENDED, not appended, and that is the one hard ordering
         * requirement in the whole design: StartSession reads session.cookie
         * once when it opens the session, so anything that renames the cookie
         * has to run before it. Appended, a staff request on the admin host
         * would open the citizen session cookie and the separation between the
         * two accounts would exist only in the documentation.
         *
         * Both groups, because both carry authenticated traffic: the `web`
         * group serves Fortify's login endpoints and Sanctum's csrf-cookie,
         * and the `api` group serves everything under /api/v1 that a signed-in
         * citizen or staff member calls.
         */
        $middleware->prependToGroup('web', ConfigureAuthForHost::class);
        $middleware->prependToGroup('api', ConfigureAuthForHost::class);

        /*
         * Refuses a route to a host it does not belong to — 404, never 403, so
         * the staff surface is indistinguishable from a typo when seen from
         * the public host (docs/12 §16).
         */
        $middleware->alias([
            'auth.host' => RequireAuthContext::class,
        ]);

        /*
         * Which callers may set X-Forwarded-*, and therefore decide what
         * $request->ip() returns (config/security.php).
         *
         * This was trustProxies(at: '*'). That is harmless exactly as long as
         * nothing in the application reads the client address — and rate
         * limiting reads it, the audit log will read it, and issue reports
         * will record it against a citizen's submission (docs/12 §12). Under
         * '*', every one of those values is chosen by whoever is being
         * identified by it.
         *
         * env() rather than config(): this closure runs while the HTTP kernel
         * is being built, and the configuration repository is not something to
         * depend on at that point. The setting is documented in
         * config/security.php with the rest of the edge configuration, and the
         * default is repeated there.
         *
         * AWS_ELB is left out of the header set. There is no load balancer
         * anywhere in this deployment, and a header that cannot legitimately
         * arrive is only something to forge.
         */
        $middleware->trustProxies(
            at: TrustedProxies::from(env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API errors are rendered as RFC 9457 problem+json in HW-E03-F02-T01.
    })->create();

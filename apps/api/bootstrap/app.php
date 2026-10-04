<?php

declare(strict_types=1);

use App\Modules\Staff\Http\Middleware\ConfigureSessionForHost;
use App\Modules\Staff\Http\Middleware\EnsureAdminHost;
use App\Modules\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api_v1.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Staff sign-in and, later, the staff API: session and CSRF from the
        // `web` group, admin host only (routes/staff.php). The host check
        // comes first, so off the admin host even a request without a CSRF
        // token gets the 404 — a 419 would say there is something here.
        then: function (): void {
            Route::middleware([EnsureAdminHost::class, 'web'])->prefix('api/v1/staff')->group(base_path('routes/staff.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tenancy resolution middleware is registered here in HW-E29-F03-T01.
        // The session cookie is chosen from the host before any session
        // starts: hw_staff_session on the admin host, hw_session elsewhere
        // (docs/12 §11.1). Appended, not prepended: it must run after
        // TrustProxies, or behind the web tier it sees the proxy's address
        // instead of the forwarded admin host. Sessions start in the route
        // groups, after every global middleware. Citizen guard switching
        // joins it in HW-E30-F01-T01.
        $middleware->append(ConfigureSessionForHost::class);

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

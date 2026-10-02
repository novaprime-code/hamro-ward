<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\User;
use App\Modules\Auth\Enums\AuthContext;
use App\Modules\Auth\Support\AuthHosts;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * HW-E30-F01-T01 — host-based auth configuration (docs/12 §11.2, D-018).
 *
 * The question the spike had to answer: can one Laravel application run
 * Fortify for two guards, one per host, by switching configuration?
 *
 * Half of it, yes. The other half is what these tests pin down, because it is
 * the half that fails silently: Fortify bakes `auth:{guard}` into its route
 * middleware when the routes are REGISTERED, so a single set of routes is
 * pinned to one guard forever and the second guard's users are refused from
 * their own logout endpoint. AuthServiceProvider registers the route file once
 * per host instead. If anyone later "simplifies" that back to one registration,
 * the guard assertions below are what should break.
 */
/*
 * The hosts come from phpunit.xml, not from a config() call here, and that is
 * not a style choice: the two copies of the Fortify routes are declared with
 * Route::domain() while the application boots, so reassigning config('hosts.*')
 * in a test would move what AuthHosts resolves while leaving the route table
 * on the old names. The two would then disagree, and the failure would look
 * like a bug in the middleware rather than in the test.
 */

// ---------------------------------------------------------------------------
// Host → context resolution
// ---------------------------------------------------------------------------

it('maps each configured host to its own account type', function (): void {
    expect(AuthHosts::contextForHost('hamroward.test'))->toBe(AuthContext::Citizen)
        ->and(AuthHosts::contextForHost('admin.hamroward.test'))->toBe(AuthContext::Staff);
});

it('treats an unknown host as neither account type', function (): void {
    /*
     * There is deliberately no default. A hostname nobody configured is
     * usually a misdirected proxy or a domain pointed at this container early,
     * and the wrong answer to either is to start issuing sessions under it.
     */
    expect(AuthHosts::contextForHost('hamroward.test.evil.example'))->toBeNull()
        ->and(AuthHosts::contextForHost('staging.hamroward.test'))->toBeNull()
        ->and(AuthHosts::contextForHost(''))->toBeNull();
});

it('ignores case, ports and the trailing dot when matching a host', function (): void {
    // All three are the admin host as far as a browser is concerned, and a
    // comparison that misses any of them locks staff out of their own site.
    expect(AuthHosts::contextForHost('ADMIN.Hamroward.Test'))->toBe(AuthContext::Staff)
        ->and(AuthHosts::contextForHost('admin.hamroward.test:8443'))->toBe(AuthContext::Staff)
        ->and(AuthHosts::contextForHost('admin.hamroward.test.'))->toBe(AuthContext::Staff);
});

it('offers both hosts to Sanctum as stateful, and nothing else', function (): void {
    // The optional api. host must never appear here: it carries no
    // cookie-authenticated route, so listing it would grant session
    // authentication to the one host that must not have it (docs/12 §11.2).
    expect(AuthHosts::statefulDomains())
        ->toEqualCanonicalizing(['hamroward.test', 'admin.hamroward.test']);
});

// ---------------------------------------------------------------------------
// What ConfigureAuthForHost sets, per host
// ---------------------------------------------------------------------------

it('configures the session cookie and every guard from the host', function (string $host, string $cookie, string $guard, string $broker): void {
    Route::middleware('web')->get('/_test/auth-config', fn (): array => [
        'session_cookie' => config('session.cookie'),
        'auth_guard' => config('auth.defaults.guard'),
        'auth_broker' => config('auth.defaults.passwords'),
        'fortify_guard' => config('fortify.guard'),
        'fortify_passwords' => config('fortify.passwords'),
        'sanctum_guard' => config('sanctum.guard'),
    ]);

    $this->get("http://{$host}/_test/auth-config")
        ->assertOk()
        ->assertJson([
            'session_cookie' => $cookie,
            'auth_guard' => $guard,
            'auth_broker' => $broker,
            'fortify_guard' => $guard,
            'fortify_passwords' => $broker,
            'sanctum_guard' => [$guard],
        ]);
})->with([
    'public host' => ['hamroward.test', 'hw_session', 'web', 'users'],
    'admin host' => ['admin.hamroward.test', 'hw_staff_session', 'staff', 'staff_users'],
]);

it('sets the cookie name before the session is opened, not after', function (): void {
    /*
     * The one hard ordering requirement in the design. StartSession reads
     * session.cookie once, when it opens the session; if ConfigureAuthForHost
     * ran after it, config would look right to a controller while the cookie
     * actually sent to the browser was the citizen one.
     *
     * So this asserts the cookie on the RESPONSE, which is the only place the
     * ordering is observable. Reading config would pass either way, which is
     * exactly why that would be the wrong assertion.
     */
    Route::middleware('web')->get('/_test/open-session', function (): string {
        session()->put('probe', 'x');

        return 'ok';
    });

    $names = collect($this->get('http://admin.hamroward.test/_test/open-session')
        ->assertOk()
        ->headers->getCookies())->map(fn ($c): string => $c->getName());

    expect($names)->toContain('hw_staff_session')
        ->and($names)->not->toContain('hw_session');
});

// ---------------------------------------------------------------------------
// The guard baked into Fortify's routes — the actual spike finding
// ---------------------------------------------------------------------------

it('bakes a different guard into each host copy of the Fortify routes', function (): void {
    /*
     * This is the finding, asserted directly against the route table.
     *
     * Fortify writes 'auth:'.config('fortify.guard') into its route middleware
     * at registration time. One registration therefore means one guard for
     * both hosts, and no amount of per-request configuration can change it —
     * which would leave every authenticated staff endpoint demanding a citizen
     * session.
     */
    /*
     * gatherMiddleware() returns what the route was declared with — the alias
     * 'auth:web' — rather than the resolved Authenticate class that
     * `route:list` prints. Both spellings are matched so this keeps working if
     * the alias is ever expanded at declaration time.
     */
    $guardFor = function (string $host, string $uri): ?string {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getDomain() !== $host || $route->uri() !== $uri) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                if (str_starts_with($middleware, 'auth:') || str_contains($middleware, 'Authenticate:')) {
                    return explode(':', $middleware)[1];
                }
            }
        }

        return null;
    };

    expect($guardFor('hamroward.test', 'logout'))->toBe('web')
        ->and($guardFor('admin.hamroward.test', 'logout'))->toBe('staff')
        ->and($guardFor('hamroward.test', 'user/password'))->toBe('web')
        ->and($guardFor('admin.hamroward.test', 'user/password'))->toBe('staff');
});

it('offers registration and password reset on the public host only', function (): void {
    /*
     * Staff accounts are invite-only (docs/12 §11.1), and this is enforced by
     * the route simply not existing on the admin host rather than by a check.
     *
     * It matters more than it looks: with a shared route set, POST /register on
     * the admin host would run the citizen registration action and then log the
     * new row in through the STAFF guard — creating an account in one table and
     * authenticating it against another.
     */
    $exists = function (string $host, string $uri): bool {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getDomain() === $host && $route->uri() === $uri) {
                return true;
            }
        }

        return false;
    };

    expect($exists('hamroward.test', 'register'))->toBeTrue()
        ->and($exists('admin.hamroward.test', 'register'))->toBeFalse()
        ->and($exists('hamroward.test', 'forgot-password'))->toBeTrue()
        ->and($exists('admin.hamroward.test', 'forgot-password'))->toBeFalse()
        ->and($exists('hamroward.test', 'email/verify/{id}/{hash}'))->toBeTrue()
        ->and($exists('admin.hamroward.test', 'email/verify/{id}/{hash}'))->toBeFalse();
});

it('serves the login endpoint on both hosts, against different tables', function (): void {
    $citizen = User::factory()->create(['email' => 'nabin@example.test']);
    $staff = StaffUser::factory()->create(['email' => 'moderator@example.test']);

    // Each host authenticates against its own table and knows nothing of the
    // other's: the citizen's address is not a staff account, and vice versa.
    // postJson: the SPA talks JSON, and Fortify answers a non-XHR post with a
    // redirect-back instead of a status worth asserting on.
    $this->postJson('http://hamroward.test/login', [
        'email' => $staff->email,
        'password' => 'password',
    ])->assertStatus(422);

    $this->postJson('http://admin.hamroward.test/login', [
        'email' => $citizen->email,
        'password' => 'password',
    ])->assertStatus(422);
});

// ---------------------------------------------------------------------------
// The host gate
// ---------------------------------------------------------------------------

it('refuses a route to the wrong host with 404, never 403', function (): void {
    Route::middleware(['web', 'auth.host:staff'])->get('/_test/staff-only', fn (): string => 'staff');
    Route::middleware(['web', 'auth.host:citizen'])->get('/_test/citizen-only', fn (): string => 'citizen');

    $this->get('http://admin.hamroward.test/_test/staff-only')->assertOk();
    $this->get('http://hamroward.test/_test/citizen-only')->assertOk();

    /*
     * 404 and not 403: from the public host the staff surface should be
     * indistinguishable from a path that was never built. A 403 confirms the
     * route exists and that the caller is merely missing something, which is
     * the one fact worth withholding (docs/12 §16).
     */
    $this->get('http://hamroward.test/_test/staff-only')->assertNotFound();
    $this->get('http://admin.hamroward.test/_test/citizen-only')->assertNotFound();
});

it('refuses both contexts on an unrecognised host', function (): void {
    Route::middleware(['web', 'auth.host:staff'])->get('/_test/staff-only', fn (): string => 'staff');
    Route::middleware(['web', 'auth.host:citizen'])->get('/_test/citizen-only', fn (): string => 'citizen');

    $this->get('http://wrong.example/_test/staff-only')->assertNotFound();
    $this->get('http://wrong.example/_test/citizen-only')->assertNotFound();
});

it('fails closed when the middleware argument is not a real context', function (): void {
    // 'auth.host:stafff' must not match nothing and therefore allow everything.
    Route::middleware(['web', 'auth.host:stafff'])->get('/_test/typo', fn (): string => 'reachable');

    $this->get('http://admin.hamroward.test/_test/typo')->assertNotFound();
    $this->get('http://hamroward.test/_test/typo')->assertNotFound();
});

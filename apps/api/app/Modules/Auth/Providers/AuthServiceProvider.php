<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Enums\AuthContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

/**
 * Registers Fortify's routes once per host (HW-E30-F01-T01, docs/D-018).
 *
 * ## Why this class exists
 *
 * docs/12 §11.2 proposed configuring Fortify for two guards at request time,
 * in ConfigureAuthForHost. Half of that works and half of it cannot:
 *
 * Fortify resolves its StatefulGuard through `$this->app->bind(...)` — a bind,
 * not a singleton — so `config('fortify.guard')` is re-read every time a
 * controller asks for it. Switching it per request therefore does change which
 * guard a login attempt authenticates against.
 *
 * But Fortify's route file reads the same config while it is REGISTERING, and
 * bakes the result into each route's middleware:
 *
 *     Route::post('/logout', ...)->middleware(['auth:'.config('fortify.guard')]);
 *     Route::post('/login',  ...)->middleware(['guest:'.config('fortify.guard')]);
 *
 * Registration happens once, when the application boots, before any request
 * exists. So with a single set of routes every authenticated Fortify route —
 * logout, password update, the whole two-factor group — is pinned to one guard
 * forever, and a staff member on the admin host hits `auth:web`, is not a
 * citizen, and is refused. `config('fortify.features')` has the same problem
 * one level up: it decides which routes exist at all, so registration cannot
 * be open on one host and closed on the other.
 *
 * ## What this does instead
 *
 * Turn Fortify's own registration off and load its route file once per
 * context, with that context's config in place each time, inside a
 * `Route::domain()` group:
 *
 *     hamroward.np        guard web    → login, register, resets, verification
 *     admin.hamroward.np  guard staff  → login, password update, two-factor
 *
 * Each copy gets the right guard baked in, the right feature set, and a name
 * prefix so the two do not collide in the route-name lookup. Fortify's
 * controllers and actions are reused untouched.
 *
 * This is cheaper than the fallback docs/12 §11.2 allowed for — "two thin
 * route groups with custom Fortify-style controllers for the staff guard" —
 * because nothing is reimplemented. The two route groups are real; the
 * controllers behind them are Fortify's own.
 *
 * ## The consequence to remember
 *
 * Because the routes are declared with `Route::domain()`, the staff login
 * endpoint does not exist on the public host — not 403, not matchable. That is
 * the behaviour docs/12 §16 asks for, and it is enforced by routing rather
 * than by a check somebody has to remember to add.
 *
 * It also means **`php artisan route:cache` bakes the hostnames in.** Changing
 * HW_PUBLIC_HOST or HW_ADMIN_HOST requires clearing the route cache, exactly
 * as changing a route does. Noted in docs/D-018 and in the deploy runbook.
 */
final class AuthServiceProvider extends ServiceProvider
{
    /**
     * Fortify's own provider registers before this one (package providers come
     * before application providers), and it registers routes in boot(). Every
     * register() runs before every boot(), so flipping the flag here is in
     * time to stop it.
     */
    public function register(): void
    {
        Fortify::$registersRoutes = false;
    }

    public function boot(): void
    {
        $this->registerFortifyRoutesPerHost();
    }

    private function registerFortifyRoutesPerHost(): void
    {
        $config = $this->app->make('config');

        // Restored afterwards so that what is left in config is the citizen
        // default rather than whichever context happened to be registered
        // last. ConfigureAuthForHost overwrites it per request anyway, but a
        // request on an unrecognised host never reaches that branch.
        $original = [
            'fortify.guard' => $config->get('fortify.guard'),
            'fortify.passwords' => $config->get('fortify.passwords'),
            'fortify.features' => $config->get('fortify.features'),
            'fortify.home' => $config->get('fortify.home'),
        ];

        foreach (AuthContext::cases() as $context) {
            $host = $context->host();

            if ($host === null) {
                continue;
            }

            $config->set([
                'fortify.guard' => $context->guard(),
                'fortify.passwords' => $context->passwordBroker(),
                'fortify.features' => $context->fortifyFeatures(),
                'fortify.home' => $context->home(),
            ]);

            Route::domain($host)
                ->name($context->routeNamePrefix())
                ->group(base_path('vendor/laravel/fortify/routes/routes.php'));
        }

        $config->set($original);
    }
}

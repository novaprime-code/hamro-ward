<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Enums\AuthContext;
use App\Modules\Auth\Support\AuthHosts;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points the session and the auth guards at the right account type for this
 * request's host (docs/12 §11.2).
 *
 * **This must run before StartSession.** StartSession reads
 * `session.cookie` once, when it opens the session, and everything after it
 * holds a session that is already named. Register it with prependToGroup()
 * rather than appendToGroup(), or a staff request reads the citizen cookie and
 * the separation this class exists for is gone.
 *
 * What it sets, and why each one:
 *
 *   session.cookie      which cookie the browser is asked for
 *   auth.defaults       the guard `Auth::user()` means with no argument
 *   fortify.guard       the StatefulGuard Fortify's controllers log in with
 *   fortify.passwords   which broker sends and checks reset tokens
 *   fortify.home        where a non-XHR login lands
 *   sanctum.guard       which guard `auth:sanctum` consults
 *
 * What it deliberately does NOT try to set: `fortify.features`, and the guard
 * baked into Fortify's own route middleware. Both are fixed when routes are
 * registered, long before any request arrives. AuthServiceProvider handles
 * those by registering Fortify's routes once per host instead; the full
 * finding is in docs/D-018.
 *
 * On an unrecognised host it changes nothing and lets the request through
 * unconfigured. RequireAuthContext is what turns that into a 404 — this class
 * only configures, so that a public read on an odd host still works and only
 * authentication is refused.
 */
final class ConfigureAuthForHost
{
    public function __construct(
        private readonly Config $config,
        private readonly SessionManager $session,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = AuthHosts::contextFor($request);

        if ($context instanceof AuthContext) {
            $this->forgetStateFromAnEarlierHost($context);
            $this->apply($context);
        }

        return $next($request);
    }

    /**
     * Drops the container state that remembers the previous request's host.
     *
     * Setting configuration is not enough on its own, and this is where most
     * of the spike's time went (D-018). Three objects in a stock Laravel +
     * Fortify application capture the host's identity when they are first
     * built and then keep it:
     *
     * **1. The session store — the worst of the three.** SessionManager bakes
     * `session.cookie` into the Store it builds and caches that Store. Setting
     * the config afterwards renames nothing, so the FIRST host a process
     * serves fixes the cookie name for every later request. Measured: a
     * citizen login followed by a staff login put `login_web_…` and
     * `login_staff_…` in ONE session row, read through one cookie — both
     * identities in one session, which is precisely the confusion docs/12 §16
     * names as the threat.
     *
     * **2. Fortify's two-factor action**, registered with `$app->scoped(...)`,
     * which takes a StatefulGuard in its constructor. Built during a citizen
     * login it holds the `web` guard, and the next staff login is then checked
     * against the CITIZENS table and refused with "these credentials do not
     * match our records". Measured: 422, and 200 once the scoped instance was
     * cleared.
     *
     * **3. AuthManager's guard cache**, each guard holding the session store it
     * was constructed with.
     *
     * One request per container hides all three, so none of them appear under
     * PHP-FPM today. They appear the moment a container is reused: Octane, a
     * queue worker, or a test that signs two people in. "Works in production,
     * fails only in tests" would be the wrong reading — the container lifetime
     * is the only difference between the two, and depending on it means
     * depending on never running a worker.
     *
     * All of this runs before anything else in the request, so nothing is
     * dropped mid-flight, and it is cheap: in a single-request process the
     * scoped list is empty and the drivers have not been built yet.
     */
    private function forgetStateFromAnEarlierHost(AuthContext $context): void
    {
        $wanted = $context->sessionCookie();

        /*
         * Only when a Store has ALREADY been built under a different name.
         *
         * The condition is the whole correctness of this method, and getting it
         * wrong is worse than not having it. Forgetting unconditionally
         * rebuilds the Store on every request, which hands StartSession a
         * brand-new empty session each time: login succeeds, writes its key,
         * sets its cookie — and the next request builds another empty Store,
         * so nobody is ever signed in. Measured: every session-resumption test
         * failed with the user null, while the leak tests "passed" for the
         * worst possible reason, which is that nothing was being resumed at
         * all.
         *
         * getDrivers() returns only Stores that exist; it does not build one.
         * So on the first request of a process there is nothing to compare
         * against, nothing is discarded, and StartSession builds the Store
         * correctly from the config set just below.
         */
        foreach ($this->session->getDrivers() as $store) {
            if ($store->getName() === $wanted) {
                continue;
            }

            /*
             * A Store built for the other host, or with the application
             * default name before any host was known. Discard it, and the
             * singleton holding a reference to it: forgetDrivers() alone is
             * not enough, because `session.store` is a separate singleton
             * resolved from the manager and would keep returning the old
             * object.
             */
            $this->session->forgetDrivers();
            App::forgetInstance('session.store');

            // Guards, each holding the Store just discarded.
            Auth::forgetGuards();

            // Per-request bindings — which is what `scoped` means. The
            // framework clears these between requests wherever a container is
            // reused; here the host has changed, which is the same situation.
            App::forgetScopedInstances();

            return;
        }
    }

    private function apply(AuthContext $context): void
    {
        $this->config->set([
            'session.cookie' => $context->sessionCookie(),

            /*
             * auth.defaults matters more than it looks. Without it, `auth()`
             * with no guard name, Fortify's own `Auth::guard(null)` fallback
             * and any `$request->user()` outside an explicit guard all resolve
             * to the `web` guard — which on the admin host means a staff
             * request silently asking the citizens table about itself.
             */
            'auth.defaults.guard' => $context->guard(),
            'auth.defaults.passwords' => $context->passwordBroker(),

            'fortify.guard' => $context->guard(),
            'fortify.passwords' => $context->passwordBroker(),
            'fortify.home' => $context->home(),

            'sanctum.guard' => [$context->guard()],
        ]);
    }
}

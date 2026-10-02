<?php

declare(strict_types=1);

namespace App\Modules\Support\Providers;

use App\Modules\Support\InternalClients;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters for the API (config/security.php).
 *
 * One limiter, two ceilings. Which one a request gets depends on whether it
 * came from the application's own web tier or from somewhere else:
 *
 *  - the web tier is the only caller in normal operation, and every request it
 *    makes stands for many visitors, so it gets a ceiling high enough that real
 *    traffic never reaches it and low enough that a retry loop does;
 *  - anything else is not supposed to exist, and gets the public limit.
 *
 * Both are keyed by address, so a limit spent by one caller is not a limit
 * taken from another.
 *
 * What this deliberately does not attempt is a per-visitor limit. Laravel
 * cannot see visitors: they are behind the web tier, which fetches the API over
 * the private network on their behalf, so counting them here would mean
 * counting all of them as one and letting the first busy minute lock out the
 * country. That limit lives in apps/web/src/middleware.ts, at the last layer
 * that can still tell visitors apart.
 */
final class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('public-read', function (Request $request): Limit {
            $address = $request->ip();

            $perMinute = InternalClients::matches($address)
                ? (int) config('security.throttle.internal_read_per_minute')
                : (int) config('security.throttle.public_read_per_minute');

            /*
             * by() needs a stable key. A request with no resolvable address
             * must not share a bucket with every other such request — that
             * would let one of them spend the limit for all — so it falls back
             * to a per-request key, which is effectively no limit. Not a hole:
             * a request with no address cannot reach this application over the
             * network in any deployed configuration.
             */
            return Limit::perMinute($perMinute)->by($address ?? 'unknown:'.$request->fingerprint());
        });

        $this->registerAuthLimiters();
    }

    /**
     * The limiters config/fortify.php names (docs/12 §11.3).
     *
     * Unlike `public-read`, these see the real visitor. Authenticated traffic
     * reaches Laravel from the browser through the same-origin proxy rather
     * than from the web tier, so the client address is the person's own and
     * counting it means something.
     */
    private function registerAuthLimiters(): void
    {
        /*
         * 5 attempts a minute, keyed on the submitted address AND the client
         * address together.
         *
         * Both halves matter. Keyed on the address alone, anyone who knows a
         * moderator's email could spend that moderator's five attempts a
         * minute from anywhere and lock them out of their own account — a
         * denial of service dressed up as a security control. Keyed on the
         * client address alone, a household or an office behind one NAT shares
         * a budget, which on mobile networks in Nepal can mean a whole city.
         */
        RateLimiter::for('login', function (Request $request): Limit {
            $username = (string) config('fortify.username', 'email');
            $submitted = mb_strtolower((string) $request->input($username, ''));

            return Limit::perMinute(5)->by($submitted.'|'.($request->ip() ?? 'unknown'));
        });

        /*
         * The second factor, limited separately. Reaching this step already
         * proves the password, so the budget is per session rather than per
         * address: six guesses at a six-digit code is nowhere near enough to
         * matter, and a shared address must not let one person's attempts
         * block another's.
         */
        RateLimiter::for('two-factor', function (Request $request): Limit {
            return Limit::perMinute(6)->by($request->session()->get('login.id') ?? $request->ip() ?? 'unknown');
        });

        /*
         * Verification email resends: 3 an hour (docs/12 §11.3). Keyed on the
         * authenticated user when there is one, because this endpoint is
         * reached while signed in but unverified, and on the address
         * otherwise.
         */
        RateLimiter::for('verification', function (Request $request): Limit {
            return Limit::perHour(3)->by($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown');
        });
    }
}

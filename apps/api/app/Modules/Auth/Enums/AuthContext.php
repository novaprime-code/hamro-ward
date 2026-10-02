<?php

declare(strict_types=1);

namespace App\Modules\Auth\Enums;

/**
 * Which kind of account a request is being authenticated as (docs/12 §11.1).
 *
 * Everything that differs between a citizen request and a staff request is
 * named here rather than scattered across middleware, config and route files:
 * the guard, the user provider, the password broker, the session cookie, and
 * which Fortify features that host offers.
 *
 * The two are deliberately separate accounts. The same person may be both a
 * citizen and a moderator, and when they are they hold two logins with two
 * passwords. Nothing maps one onto the other, because the whole point of the
 * separation is that a moderator reading a report is not also the citizen who
 * filed it.
 */
enum AuthContext: string
{
    case Citizen = 'citizen';
    case Staff = 'staff';

    /**
     * The guard name, which is also the user-provider name and the password
     * broker name. Kept identical on purpose: three names for one thing is
     * three chances to wire a staff login to the citizens table.
     */
    public function guard(): string
    {
        return match ($this) {
            self::Citizen => 'web',
            self::Staff => 'staff',
        };
    }

    public function provider(): string
    {
        return match ($this) {
            self::Citizen => 'users',
            self::Staff => 'staff_users',
        };
    }

    public function passwordBroker(): string
    {
        return $this->provider();
    }

    /**
     * The session cookie name.
     *
     * Two different names, not one name on two hosts. Host-only cookies
     * already keep them apart in a correctly configured browser, but a
     * misconfigured SESSION_DOMAIN would silently merge them, and a single
     * shared name is the difference between that mistake being visible and it
     * handing a citizen a staff session (docs/12 §16).
     */
    public function sessionCookie(): string
    {
        return match ($this) {
            self::Citizen => 'hw_session',
            self::Staff => 'hw_staff_session',
        };
    }

    /**
     * Where Fortify redirects after a successful login.
     *
     * The API answers with JSON, so this is only reached by a non-XHR post —
     * which should not happen, and if it does the reader belongs on the host
     * they signed in on, not on the other one.
     */
    public function home(): string
    {
        return match ($this) {
            self::Citizen => '/',
            self::Staff => '/queue',
        };
    }

    /**
     * The configured hostname for this context.
     */
    public function host(): ?string
    {
        $key = match ($this) {
            self::Citizen => 'hosts.public',
            self::Staff => 'hosts.admin',
        };

        $host = config($key);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Fortify features this host offers.
     *
     * These cannot be switched per request: Fortify reads them while
     * REGISTERING its routes, so they decide which routes exist rather than
     * what a route does (see AuthServiceProvider). Which is why they belong on
     * the context and not in config/fortify.php — there is no single answer.
     *
     * Staff accounts are invite-only (docs/12 §11.1), so the admin host offers
     * no registration, no password-reset request and no email verification. A
     * staff member who has lost their password asks an operator admin, which
     * is a smaller attack surface than a reset form on the host that holds
     * moderation.
     *
     * @return list<string>
     */
    public function fortifyFeatures(): array
    {
        return match ($this) {
            self::Citizen => [
                'registration',
                'reset-passwords',
                'email-verification',
                'update-profile-information',
                'update-passwords',
                'two-factor-authentication',
            ],
            self::Staff => [
                'update-passwords',
                'two-factor-authentication',
            ],
        };
    }

    /**
     * Route-name prefix for this context's copy of the Fortify routes.
     *
     * Fortify's route file names its routes `login`, `logout` and so on. It is
     * loaded once per context, so without a prefix the second copy would
     * overwrite the first in the name lookup and route('login') would resolve
     * to whichever host happened to register last.
     */
    public function routeNamePrefix(): string
    {
        return match ($this) {
            self::Citizen => '',
            self::Staff => 'staff.',
        };
    }
}

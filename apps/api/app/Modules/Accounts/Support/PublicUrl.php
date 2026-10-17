<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use Illuminate\Support\Facades\URL;

/**
 * Links that are meant to be clicked (docs/12 §11.4).
 *
 * Every URL this application puts in an email has to point at the web tier,
 * not at itself: the browser never talks to Laravel directly, it talks to
 * Next.js, which proxies /email/*, /login and the rest back here
 * (apps/web/next.config.ts). APP_URL is this container's address on the
 * Docker network and is useless in an inbox.
 *
 * For a SIGNED link that distinction is not cosmetic. Laravel signs the whole
 * URL and checks the signature against the URL the request arrives on, so a
 * link signed with APP_URL and clicked on the public host fails every time.
 * forceRootUrl makes the generator sign the address the person will really
 * ask for; it is restored immediately, because it is global state and a
 * queued mail run would otherwise leak it into the next job.
 */
final class PublicUrl
{
    /** The web tier's origin, or null when FRONTEND_URL is unset. */
    public static function root(): ?string
    {
        $root = (string) config('accounts.frontend_url');

        return $root === '' ? null : $root;
    }

    /**
     * `{frontend}/{locale}/{path}`, for a link with nothing secret in it.
     *
     * @param  array<string, string>  $query
     */
    public static function to(string $locale, string $path, array $query = []): string
    {
        $url = sprintf('%s/%s/%s', self::root() ?? (string) config('app.url'), $locale, ltrim($path, '/'));

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * A temporary signed route, as an absolute link on the public origin.
     *
     * Signed RELATIVELY — over the path and query, never the host. The
     * browser asks the web tier, which proxies the request on to this
     * application under a different name entirely (a container address on the
     * Docker network), so a signature covering the host could not survive the
     * trip: it would be made for one URL and checked against another. The
     * path is the part that stays the same end to end, and it is the part
     * worth protecting; the route validates with `signed:relative` to match.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function temporarySignedRoute(string $name, \DateTimeInterface $expiration, array $parameters): string
    {
        $relative = URL::temporarySignedRoute($name, $expiration, $parameters, absolute: false);

        return (self::root() ?? rtrim((string) config('app.url'), '/')).$relative;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Enums\AuthContext;
use Illuminate\Http\Request;

/**
 * Turns a request's host into an AuthContext, or into nothing (docs/12 §11.2).
 *
 * "Or into nothing" is the important half. There is no default context: a
 * request whose host is neither the public host nor the admin host is not
 * authenticated as anything, and the host gate answers 404. Choosing a default
 * would mean that the day a proxy is misconfigured — or a new hostname is
 * pointed at this container before anyone has configured it — the application
 * starts issuing sessions under a name nobody intended.
 */
final class AuthHosts
{
    /**
     * The context a request belongs to, or null if its host is not one of ours.
     *
     * $request->getHost() already accounts for X-Forwarded-Host, but only from
     * the proxies named in config/security.php. From anyone else the forwarded
     * header is ignored and the real Host header is used, so an untrusted
     * caller cannot talk its way onto the admin host.
     */
    public static function contextFor(Request $request): ?AuthContext
    {
        return self::contextForHost($request->getHost());
    }

    public static function contextForHost(string $host): ?AuthContext
    {
        $host = self::normalize($host);

        if ($host === '') {
            return null;
        }

        foreach (AuthContext::cases() as $context) {
            $configured = $context->host();

            if ($configured !== null && self::normalize($configured) === $host) {
                return $context;
            }
        }

        return null;
    }

    /**
     * The hosts Sanctum treats as stateful — the two it may accept cookie
     * authentication from, and no others (docs/12 §11.2).
     *
     * The optional `api.` host is deliberately absent: it exists for future
     * partner APIs and serves no cookie-authenticated route, so adding it here
     * would grant session authentication to the one host that must never have
     * it.
     *
     * @return list<string>
     */
    public static function statefulDomains(): array
    {
        $hosts = [];

        foreach (AuthContext::cases() as $context) {
            $host = $context->host();

            if ($host !== null) {
                $hosts[] = self::normalize($host);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Lower-cased, port-stripped, trailing-dot-stripped.
     *
     * Hostnames are case-insensitive, so "Admin.Hamroward.np" is the admin
     * host. A port never belongs in the comparison: behind a proxy the
     * configured name has none while the Host header may carry one. The
     * trailing dot is the fully-qualified form of the same name, and a browser
     * that sends it would otherwise be refused.
     */
    private static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');

        if (str_contains($host, ':')) {
            $host = (string) strstr($host, ':', before_needle: true);
        }

        return $host;
    }
}

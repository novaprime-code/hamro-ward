<?php

declare(strict_types=1);

namespace App\Modules\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether an address belongs to this stack's own network rather than to the
 * public internet (config/security.php).
 *
 * One implementation, because there were two and they disagreed. The health
 * endpoint decided with str_starts_with($ip, '172.'), which matches the whole
 * of 172.0.0.0/8 — most of which is public address space, including ranges
 * Google routes on. The private block is 172.16.0.0/12, a sixteenth of that.
 * Prefix matching on a dotted string cannot express a /12, which is why this
 * uses a range matcher instead.
 *
 * IpUtils handles addresses and CIDR ranges, IPv4 and IPv6.
 */
final class InternalClients
{
    public static function matches(?string $address): bool
    {
        if ($address === null || $address === '') {
            return false;
        }

        $ranges = self::ranges();

        return $ranges !== [] && IpUtils::checkIp($address, $ranges);
    }

    /**
     * @return list<string>
     */
    private static function ranges(): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', (string) config('security.internal_clients', ''))),
            static fn (string $range): bool => $range !== '',
        ));
    }
}

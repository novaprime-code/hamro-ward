<?php

declare(strict_types=1);

namespace App\Modules\Support;

/**
 * Turns the TRUSTED_PROXIES setting into the shape Laravel's trustProxies()
 * expects: either the literal '*' or a list of addresses and CIDR ranges.
 *
 * A class rather than three lines inline in bootstrap/app.php for two reasons.
 * Nothing in bootstrap/app.php is reachable from a test, and this is a decision
 * about which callers may rename themselves — the kind that should be pinned by
 * a test rather than reviewed by eye. The other is that bootstrap/app.php runs
 * before the configuration repository is guaranteed to exist, so the value has
 * to come from env() there; keeping the parsing here means the awkward part is
 * one function call and not a block of logic in a file nobody can exercise.
 *
 * @see config/security.php for what the setting means and why the default is
 *      the private ranges rather than '*'.
 */
final class TrustedProxies
{
    /**
     * @return string|array<int, string> '*' or a list of addresses/CIDR ranges
     */
    public static function from(?string $configured): string|array
    {
        $value = trim((string) $configured);

        // Trust everything. Valid on a development machine, where there is no
        // proxy to name; never on a deployed stack.
        if ($value === '*') {
            return '*';
        }

        /*
         * An empty setting trusts nothing, which is the safe reading: a stack
         * that forgot to configure this sees the address the connection
         * actually came from, rather than one the caller supplied. The visible
         * consequence is that rate limiting counts the proxy instead of the
         * visitor — wrong, but wrong in the direction that does not let a
         * caller choose its own identity.
         */
        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $entry): bool => $entry !== '',
        ));
    }
}

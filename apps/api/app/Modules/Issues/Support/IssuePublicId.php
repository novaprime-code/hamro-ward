<?php

declare(strict_types=1);

namespace App\Modules\Issues\Support;

use InvalidArgumentException;

/**
 * The public face of a report: `5f3a-7K2MQ9XW4R` (docs/05 §6.2, docs/12 §6).
 *
 * The first four characters are the start of the tenant key, so a URL or a
 * tracking code read out over the phone names its municipality without a
 * lookup across every tenant database. Central keeps that prefix unique
 * (tenants_key_prefix_unique), so it names exactly one.
 *
 * The rest is ten Crockford base32 characters — 50 random bits, no I, L, O or
 * U — chosen because people copy these by hand: nothing in it can be misread
 * as another character, and a lowercase or mistyped copy is normalised back.
 * Uniqueness within the tenant is the table's unique index; the caller retries
 * on the rare collision.
 */
final class IssuePublicId
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const PATTERN = '/^([0-9a-f]{4})-([0-9A-HJKMNP-TV-Z]{10})$/';

    public static function generate(string $tenantKey): string
    {
        if (preg_match('/^[0-9a-f]{8}$/', $tenantKey) !== 1) {
            throw new InvalidArgumentException("Not a tenant key: [{$tenantKey}].");
        }

        $bytes = random_bytes(10);
        $id = '';

        for ($index = 0; $index < 10; $index++) {
            $id .= self::ALPHABET[ord($bytes[$index]) & 31];
        }

        return substr($tenantKey, 0, 4).'-'.$id;
    }

    /**
     * The canonical form of a code as a person typed it, or null when it
     * cannot be one: case folded, the look-alikes Crockford defines mapped
     * back (I and L to 1, O to 0), stray spaces and dashes tolerated.
     */
    public static function normalise(string $input): ?string
    {
        $compact = strtoupper((string) preg_replace('/[\s-]+/', '', $input));

        if (strlen($compact) !== 14) {
            return null;
        }

        $prefix = strtolower(substr($compact, 0, 4));
        $id = strtr(substr($compact, 4), ['I' => '1', 'L' => '1', 'O' => '0']);
        $candidate = "{$prefix}-{$id}";

        return preg_match(self::PATTERN, $candidate) === 1 ? $candidate : null;
    }

    /** The tenant key prefix a valid public id carries. */
    public static function tenantPrefix(string $publicId): ?string
    {
        return preg_match(self::PATTERN, $publicId, $match) === 1 ? $match[1] : null;
    }
}

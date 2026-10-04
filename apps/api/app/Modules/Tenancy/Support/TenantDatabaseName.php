<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Tenant keys are 8 random lowercase hex characters, never derived from a
 * name (renames need no database rename) and never from a time-ordered UUID
 * prefix (tenants created in the same minute would collide). D-013.
 */
final class TenantDatabaseName
{
    private const KEY = '/^[0-9a-f]{8}$/';

    private const PREFIX = '/^[a-z][a-z0-9_]{0,20}$/';

    public static function forKey(string $key): string
    {
        self::assertValidKey($key);

        return self::prefix().$key;
    }

    public static function assertValidKey(string $key): void
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw TenancyException::invalidKey($key);
        }
    }

    public static function assertValid(string $name): void
    {
        $pattern = '/^'.preg_quote(self::prefix(), '/').'[0-9a-f]{8}$/';

        if (preg_match($pattern, $name) !== 1) {
            throw TenancyException::invalidDatabaseName($name);
        }
    }

    public static function generateUniqueKey(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $key = bin2hex(random_bytes(4));

            // The first four characters must be unique too: they prefix issue
            // public ids and route them to this tenant (IssuePublicId).
            if (! Tenant::query()->whereRaw('left(tenant_key, 4) = ?', [substr($key, 0, 4)])->exists()) {
                return $key;
            }
        }

        throw TenancyException::keyExhausted();
    }

    private static function prefix(): string
    {
        $prefix = (string) config('tenancy.database_prefix');

        if (preg_match(self::PREFIX, $prefix) !== 1) {
            throw TenancyException::invalidIdentifier($prefix);
        }

        return $prefix;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Exceptions\TenancyException;

/**
 * PostgreSQL identifiers cannot be bound as query parameters, so every
 * identifier we interpolate (database, role, extension names) is validated
 * against a strict pattern first and then double-quoted.
 */
final class Pg
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]{0,62}$/';

    public static function ident(string $identifier): string
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw TenancyException::invalidIdentifier($identifier);
        }

        return '"'.$identifier.'"';
    }
}

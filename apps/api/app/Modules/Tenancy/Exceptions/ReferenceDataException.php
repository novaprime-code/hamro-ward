<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Reference replication refused to run or could not finish.
 *
 * Separate from TenancyException because the remedy is different: tenancy
 * failures mean a database is unreachable or inactive, these mean the central
 * data the tenant depends on is wrong, and the fix is in the central database
 * rather than in the tenant's.
 */
final class ReferenceDataException extends RuntimeException
{
    public static function misconfiguredTenant(string $tenantKey, string $reason): self
    {
        return new self("Tenant {$tenantKey} cannot receive reference data: {$reason}.");
    }

    public static function centralTableMissing(string $table): self
    {
        return new self("Central table {$table} does not exist; run the central migrations first.");
    }
}

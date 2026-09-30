<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models\Concerns;

use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\TenantManager;

/**
 * For models whose table lives in each tenant database (docs/12 §3).
 * Using such a model before a tenant is initialized throws instead of
 * silently reading from the wrong database.
 */
trait UsesTenantConnection
{
    public function getConnectionName(): string
    {
        if (!app(TenantManager::class)->initialized()) {
            throw TenancyException::notInitialized(static::class);
        }

        return (string) config('tenancy.tenant_connection', 'tenant');
    }
}

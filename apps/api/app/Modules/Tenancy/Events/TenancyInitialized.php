<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Fired after the tenant connections point at a tenant database.
 * Listeners scope other services (cache prefix, file paths) — docs/12 §9.
 */
final readonly class TenancyInitialized
{
    public function __construct(public Tenant $tenant) {}
}

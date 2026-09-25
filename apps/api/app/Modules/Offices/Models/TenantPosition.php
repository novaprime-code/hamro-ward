<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;

/**
 * The per-tenant replica. Read-only by convention and by grant: hw_app holds
 * SELECT on this table and nothing more. Writes come from
 * SyncTenantReferenceData running as hw_owner.
 */
final class TenantPosition extends BasePosition
{
    use UsesTenantConnection;
}

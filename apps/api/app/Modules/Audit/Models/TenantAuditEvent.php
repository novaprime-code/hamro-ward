<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;

/** Changes inside one municipality, kept in its own database (docs/12 §9). */
final class TenantAuditEvent extends BaseAuditEvent
{
    use UsesTenantConnection;
}

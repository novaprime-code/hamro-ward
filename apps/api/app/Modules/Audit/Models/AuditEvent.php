<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;

/** Changes to central data: geography, persons, parties, national sources. */
final class AuditEvent extends BaseAuditEvent
{
    use UsesCentralConnection;
}

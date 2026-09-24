<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Enums;

/**
 * Where the source a link points to lives (docs/12 §3):
 * national documents in the central database, local notices in the tenant's own.
 */
enum SourceScope: string
{
    case Central = 'central';
    case Tenant = 'tenant';
}

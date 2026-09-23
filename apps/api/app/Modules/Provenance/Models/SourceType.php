<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;

/**
 * Master copy of the source hierarchy.
 */
final class SourceType extends BaseSourceType
{
    use UsesCentralConnection;
}

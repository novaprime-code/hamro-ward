<?php

declare(strict_types=1);

namespace App\Modules\Issues\Models;

use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;

/**
 * The read-only replica inside a tenant database, for issues.category_key.
 */
final class TenantIssueCategory extends BaseIssueCategory
{
    use UsesTenantConnection;

    public $timestamps = false;
}

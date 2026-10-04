<?php

declare(strict_types=1);

namespace App\Modules\Issues\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;

/**
 * The editable catalogue. Changes go through IssueCategorySeeder and reach
 * tenants through SyncTenantReferenceData.
 */
final class IssueCategory extends BaseIssueCategory
{
    use UsesCentralConnection;
}

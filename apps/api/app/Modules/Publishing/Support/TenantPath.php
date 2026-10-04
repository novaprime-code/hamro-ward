<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Support;

use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Tenancy\Models\Tenant;

/** A tenant's municipality as the slug path its public pages live under. */
final class TenantPath
{
    public static function of(Tenant $tenant): ?string
    {
        $path = AdminUnitSlug::query()
            ->where('admin_unit_id', $tenant->admin_unit_id)
            ->where('is_current', true)
            ->value('slug_path');

        return is_string($path) ? $path : null;
    }
}

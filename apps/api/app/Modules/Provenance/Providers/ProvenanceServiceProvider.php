<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Providers;

use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitCode;
use App\Modules\Geography\Models\AdminUnitName;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class ProvenanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Short, stable subject keys. They appear in database CHECK constraints
         * and in API responses, so they must not follow class names around.
         * Entries are added as their modules arrive: person, party (HW-E05),
         * office_holding, issue, promise …
         */
        Relation::enforceMorphMap([
            'admin_unit' => AdminUnit::class,
            'admin_unit_name' => AdminUnitName::class,
            'admin_unit_code' => AdminUnitCode::class,
        ]);
    }
}

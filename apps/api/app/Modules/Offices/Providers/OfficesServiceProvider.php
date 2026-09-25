<?php

declare(strict_types=1);

namespace App\Modules\Offices\Providers;

use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Models\Vacancy;
use App\Modules\Offices\Models\WardOffice;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Adds the offices subject types to the morph map the provenance module starts.
 *
 * Short keys rather than class names, for the same reason as there: a stored
 * `office_holding` survives a class rename or a move between modules, and it is
 * also what the public API returns, so renaming a PHP class must never change
 * a URL or a stored source link.
 *
 * Relation::morphMap() merges into the existing map; this provider is
 * registered after ProvenanceServiceProvider so both halves are present.
 */
final class OfficesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::morphMap([
            'person' => Person::class,
            'party' => Party::class,
            'office_holding' => OfficeHolding::class,
            'vacancy' => Vacancy::class,
            'ward_office' => WardOffice::class,
        ]);
    }
}

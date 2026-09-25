<?php

declare(strict_types=1);

namespace App\Modules\Demo\Actions;

use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Demo\Data\DemoLocalLevel;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Removes the demonstration (docs/13 §6).
 *
 * Retirement is meant to be boring: drop the tenant databases, delete the
 * central rows this module created, and stop running the seeder. No
 * application code changes, because the seeder wrote through the same schema
 * and the same actions the importer will use.
 *
 * Geography is left alone. Provinces and districts are real and may by then be
 * carrying real local levels; deleting them to tidy up a demonstration would
 * be the most expensive kind of cleanup. The demonstration local levels and
 * their wards go with their tenants.
 */
final class DropDemoData
{
    public function __construct(private readonly DropTenantDatabase $dropDatabase) {}

    /** @return array{tenants: int, people: int, parties: int} */
    public function handle(): array
    {
        $tenants = 0;

        foreach (DemoDataset::localLevels() as $demo) {
            $tenant = Tenant::query()
                ->where('admin_unit_id', DemoDataset::id('admin_unit', $demo->key))
                ->first();

            if ($tenant === null) {
                continue;
            }

            // DropTenantDatabase only acts on archived tenants outside local
            // and testing, which is the right protection — archiving first is
            // the deliberate step it asks for.
            $tenant->forceFill(['status' => TenantStatus::Archived])->save();
            $this->dropDatabase->handle($tenant);
            $tenant->delete();
            $tenants++;
        }

        $people = $this->deletePeople();
        $parties = $this->deleteParties();

        return ['tenants' => $tenants, 'people' => $people, 'parties' => $parties];
    }

    private function deletePeople(): int
    {
        $ids = [];

        foreach (DemoDataset::localLevels() as $demo) {
            for ($index = 0; $index < $this->personCeiling($demo); $index++) {
                $ids[] = DemoDataset::id('person', $demo->key, (string) $index);
            }
        }

        return Person::query()->whereIn('id', $ids)->delete();
    }

    private function deleteParties(): int
    {
        $ids = array_map(
            static fn (array $party): string => DemoDataset::id('party', $party['key']),
            DemoDataset::parties(),
        );

        return Party::query()->whereIn('id', $ids)->delete();
    }

    /** Generous upper bound, so a reduced ward count never orphans people. */
    private function personCeiling(DemoLocalLevel $demo): int
    {
        return $demo->wards * 5 + 8;
    }
}

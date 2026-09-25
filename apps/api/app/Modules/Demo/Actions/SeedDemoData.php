<?php

declare(strict_types=1);

namespace App\Modules\Demo\Actions;

use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Demo\Data\DemoLocalLevel;
use App\Modules\Demo\Support\DemoGuard;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Actions\SyncTenantReferenceData;
use App\Modules\Tenancy\Models\Tenant;

/**
 * The whole demonstration, in order (docs/13).
 *
 * geography → people and parties → a tenant per local level → seats and
 * evidence inside each tenant.
 *
 * Every step is idempotent, so this is safe to re-run: it is how the
 * demonstration is refreshed after a schema change, not a one-shot fixture.
 * Tenants that already exist are re-synced rather than recreated, because
 * dropping and rebuilding a database to change a few rows would throw away
 * anything a reviewer had clicked on.
 */
final class SeedDemoData
{
    public function __construct(
        private readonly DemoGuard $guard,
        private readonly SeedDemoGeography $geography,
        private readonly SeedDemoDirectory $directory,
        private readonly SeedDemoTenant $seedTenant,
        private readonly CreateTenant $createTenant,
        private readonly SyncTenantReferenceData $syncReference,
    ) {}

    /**
     * @param  callable(string): void|null  $progress
     * @return array<string, mixed>
     */
    public function handle(?callable $progress = null): array
    {
        $progress ??= static fn (string $line): null => null;

        $this->guard->assertMaySeed();

        $progress('Building the administrative hierarchy');
        $geography = $this->geography->handle();

        $progress('Creating people and parties');
        $directory = $this->directory->handle();

        $tenants = [];

        foreach (DemoDataset::localLevels() as $demo) {
            $progress("Onboarding {$demo->nameEn}");
            $tenant = $this->tenantFor($demo);

            $progress("Seeding seats and evidence for {$demo->nameEn}");
            $tenants[$demo->key] = [
                'tenant_key' => $tenant->tenant_key,
                'database' => $tenant->database_name,
                ...$this->seedTenant->handle($tenant, $demo),
            ];
        }

        return [
            'geography' => $geography,
            'directory' => $directory,
            'tenants' => $tenants,
        ];
    }

    /**
     * An existing tenant is re-used and its reference data refreshed; a missing
     * one is onboarded from scratch. Both paths end with a tenant whose
     * geography replica matches central, which is what the seat seeder needs.
     */
    private function tenantFor(DemoLocalLevel $demo): Tenant
    {
        $localLevel = AdminUnit::query()->findOrFail(DemoDataset::id('admin_unit', $demo->key));

        $tenant = Tenant::query()->where('admin_unit_id', $localLevel->id)->first();

        if ($tenant === null) {
            return $this->createTenant->handle($localLevel);
        }

        $this->syncReference->handle($tenant);

        return $tenant->refresh();
    }
}

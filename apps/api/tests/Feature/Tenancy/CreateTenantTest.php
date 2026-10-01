<?php

declare(strict_types=1);

use App\Modules\Geography\Actions\PublishAdminUnit;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Offices\Models\TenantPosition;
use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Actions\SyncTenantReferenceData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\ReferenceDataException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
});

it('takes a published local level all the way to active', function (): void {
    $localLevel = AdminUnit::factory()
        ->localLevel(LocalLevelType::Municipality)
        ->published()
        ->create();

    AdminUnit::factory()->ward(1)->childOf($localLevel)->published()->create();
    AdminUnit::factory()->ward(2)->childOf($localLevel)->published()->create();

    $tenant = app(CreateTenant::class)->handle($localLevel);

    try {
        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and($tenant->onboarded_at)->not->toBeNull()
            ->and($tenant->database_name)->toStartWith('hw_t_');

        // The database exists, has the schema, and carries its reference data.
        [$positions, $wards] = app(TenantManager::class)->run($tenant, fn (): array => [
            TenantPosition::query()->count(),
            TenantAdminUnit::query()->wards()->count(),
        ]);

        expect($positions)->toBeGreaterThan(0)
            ->and($wards)->toBe(2);
    } finally {
        $tenant->forceFill(['status' => TenantStatus::Archived])->save();
        app(DropTenantDatabase::class)->handle($tenant);
    }
});

it('refuses anything that is not a local level', function (): void {
    $district = AdminUnit::factory()->district()->published()->create();

    expect(fn () => app(CreateTenant::class)->handle($district))
        ->toThrow(ReferenceDataException::class);
});

/*
 * This replaces a test that asserted the opposite — that onboarding refuses
 * an unpublished local level "whose wards would replicate invisible".
 *
 * Two parts of the codebase disagreed about this, both deliberately:
 * CreateTenant's guard and that test required publication first, while
 * CreateTenantCommandTest asserted a successful onboard leaves the unit
 * unpublished, and docs/12 §4 and §6 treat the two as separate axes — an
 * active tenant serves the public only "if the local level is_published",
 * and an unpublished one 404s like any unknown place.
 *
 * Settled against the guard. Requiring publication first inverts the order
 * of the work: a tenant has to exist before anyone can load the place's
 * representatives, so the guard made every new municipality publicly visible
 * while it was still empty — a live ward page reading "not yet verified" for
 * however long the data took. On a platform whose premise is never showing a
 * claim it cannot back, that is the wrong default.
 *
 * The replication concern was real, and is covered by the next test: the
 * sync is re-runnable and does update publication.
 */
it('onboards an unpublished local level without publishing it', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create();

    $tenant = app(CreateTenant::class)->handle($localLevel);

    try {
        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and($localLevel->refresh()->is_published)->toBeFalse();
    } finally {
        app(DropTenantDatabase::class)->handle($tenant);
    }
});

it('propagates publication into the tenant when the reference data is synced again', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create();
    AdminUnit::factory()->ward(1)->childOf($localLevel)->create();

    $tenant = app(CreateTenant::class)->handle($localLevel);

    try {
        // Onboarded while unpublished: the tenant's copy says so too.
        app(TenantManager::class)->run($tenant, function (): void {
            expect(TenantAdminUnit::query()->where('is_published', true)->count())->toBe(0);
        });

        // Publishing is top-down (D-006), so the chain goes first.
        $publish = app(PublishAdminUnit::class);

        foreach ($localLevel->ancestors() as $ancestor) {
            $publish->publish($ancestor);
        }

        $publish->publish($localLevel->refresh());
        app(SyncTenantReferenceData::class)->handle($tenant->refresh());

        // After the documented re-sync the wards are visible inside the tenant.
        app(TenantManager::class)->run($tenant, function (): void {
            expect(TenantAdminUnit::query()->where('is_published', true)->count())->toBeGreaterThan(0);
        });
    } finally {
        app(DropTenantDatabase::class)->handle($tenant);
    }
});

it('refuses a second tenant for the same local level', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->published()->create();
    $existing = Tenant::factory()->forLocalLevel($localLevel)->create();

    expect(fn () => app(CreateTenant::class)->handle($localLevel))
        ->toThrow(ReferenceDataException::class);

    expect(Tenant::query()->where('admin_unit_id', $localLevel->id)->count())->toBe(1)
        ->and($existing->refresh()->status)->toBe(TenantStatus::Active);
});

<?php

declare(strict_types=1);

use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Offices\Models\Position;
use App\Modules\Offices\Models\TenantPosition;
use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Models\TenantSourceType;
use App\Modules\Tenancy\Actions\SyncTenantReferenceData;
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

it('copies the source hierarchy and the seat catalogue into the tenant', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        [$sourceTypes, $positions] = app(TenantManager::class)->run($tenant, fn (): array => [
            TenantSourceType::query()->orderBy('authority_rank')->get()->pluck('key')->all(),
            TenantPosition::query()->count(),
        ]);

        expect($sourceTypes)->toHaveCount(10)
            ->and($sourceTypes[0])->toBe(SourceTypeKey::Ecn->value)
            ->and($positions)->toBe(Position::query()->count());
    }, tenantWithPublishedWards());
});

it('copies array and json columns back as arrays, not literals', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $position = app(TenantManager::class)->run(
            $tenant,
            fn (): TenantPosition => TenantPosition::query()->findOrFail('ward_member_open'),
        );

        expect($position->applies_to_local_level_types)
            ->toContain('sub_metropolitan_city')
            ->toHaveCount(4)
            ->and($position->seatsIn('sub_metropolitan_city'))->toBe(2)
            ->and($position->appliesTo('rural_municipality'))->toBeTrue();
    }, tenantWithPublishedWards());
});

it('copies only this tenant’s branch of the hierarchy', function (): void {
    // A second municipality exists centrally and must not appear in the first
    // one's database (docs/12 §9).
    $other = AdminUnit::factory()->localLevel()->published()->create(['name_en' => 'Somewhere Else']);
    AdminUnit::factory()->ward(1)->childOf($other)->published()->create();

    withTenantDatabase(function (Tenant $tenant): void {
        $names = app(TenantManager::class)->run(
            $tenant,
            fn (): array => TenantAdminUnit::query()->get()->pluck('name_en')->all(),
        );

        expect($names)->not->toContain('Somewhere Else')
            // country, province, district, local level, 2 wards
            ->and($names)->toHaveCount(6);
    }, tenantWithPublishedWards());
});

it('brings the whole ancestor chain, so a ward knows its district', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $levels = app(TenantManager::class)->run(
            $tenant,
            fn (): array => TenantAdminUnit::query()->get()
                ->map(fn (TenantAdminUnit $unit): string => $unit->level->value)
                ->unique()->sort()->values()->all(),
        );

        expect($levels)->toBe(['country', 'district', 'local_level', 'province', 'ward']);
    }, tenantWithPublishedWards());
});

it('copies slug paths so a tenant can build its own URLs', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $ward = app(TenantManager::class)->run(
            $tenant,
            fn (): ?TenantAdminUnit => TenantAdminUnit::query()->wards()->first(),
        );

        expect($ward?->slug_path)->toMatch('#^[a-z0-9-]+/[a-z0-9-]+/[a-z0-9-]+/1$#');
    }, tenantWithPublishedWards());
});

it('carries publication state across, so an unpublished ward stays unpublished', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $published = app(TenantManager::class)->run(
            $tenant,
            fn (): array => TenantAdminUnit::query()->wards()->get()
                ->mapWithKeys(fn (TenantAdminUnit $ward): array => [
                    $ward->ward_number => $ward->is_published,
                ])->all(),
        );

        expect($published)->toBe([1 => true, 2 => false]);
    }, tenantWithOneUnpublishedWard());
});

it('is idempotent and bumps the reference version each run', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        expect($tenant->reference_version)->toBe(1);

        $result = app(SyncTenantReferenceData::class)->handle($tenant);

        $counts = app(TenantManager::class)->run($tenant, fn (): array => [
            TenantPosition::query()->count(),
            TenantAdminUnit::query()->count(),
            TenantSourceType::query()->count(),
        ]);

        expect($result['reference_version'])->toBe(2)
            ->and($counts)->toBe([Position::query()->count(), 6, 10]);
    }, tenantWithPublishedWards());
});

it('removes reference rows the central database no longer has', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        Position::query()->findOrFail('executive_member_dalit_minority')->delete();

        app(SyncTenantReferenceData::class)->handle($tenant);

        $keys = app(TenantManager::class)->run(
            $tenant,
            fn (): array => TenantPosition::query()->get()->pluck('key')->all(),
        );

        expect($keys)->not->toContain('executive_member_dalit_minority')
            ->and($keys)->toHaveCount(Position::query()->count());
    }, tenantWithPublishedWards());
});

it('carries a renamed ward through on the next sync', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        AdminUnit::query()
            ->where('parent_id', $tenant->admin_unit_id)
            ->where('ward_number', 1)
            ->update(['name_en' => 'Ward One (renamed)']);

        app(SyncTenantReferenceData::class)->handle($tenant);

        $name = app(TenantManager::class)->run(
            $tenant,
            fn (): ?string => TenantAdminUnit::query()->wards()->first()?->name_en,
        );

        expect($name)->toBe('Ward One (renamed)');
    }, tenantWithPublishedWards());
});

it('refuses to sync a tenant that does not point at a local level', function (): void {
    $district = AdminUnit::factory()->district()->create();
    $tenant = Tenant::factory()->create();

    // Bypasses the tenants_local_level trigger deliberately: the point is that
    // the sync checks for itself rather than trusting the row.
    $tenant->setRawAttributes([...$tenant->getAttributes(), 'admin_unit_id' => $district->id], true);

    expect(fn () => app(SyncTenantReferenceData::class)->handle($tenant))
        ->toThrow(ReferenceDataException::class);
});

<?php

declare(strict_types=1);

use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Offices\Models\TenantPosition;
use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
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

it('refuses an unpublished local level, whose wards would replicate invisible', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create();

    expect(fn () => app(CreateTenant::class)->handle($localLevel))
        ->toThrow(ReferenceDataException::class);
});

it('refuses a second tenant for the same local level', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->published()->create();
    $existing = Tenant::factory()->forLocalLevel($localLevel)->create();

    expect(fn () => app(CreateTenant::class)->handle($localLevel))
        ->toThrow(ReferenceDataException::class);

    expect(Tenant::query()->where('admin_unit_id', $localLevel->id)->count())->toBe(1)
        ->and($existing->refresh()->status)->toBe(TenantStatus::Active);
});

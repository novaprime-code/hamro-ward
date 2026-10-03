<?php

declare(strict_types=1);

use App\Modules\Demo\Actions\SeedDemoData;
use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
    app(SeedDemoData::class)->handle();
});

afterEach(function (): void {
    Tenant::query()->get()->each(function (Tenant $tenant): void {
        $tenant->forceFill(['status' => TenantStatus::Archived])->save();
        app(DropTenantDatabase::class)->handle($tenant);
    });
});

it('lists every published municipality and its wards', function (): void {
    $data = $this->getJson('/api/v1/published-paths')->assertOk()->collect('data');

    expect($data)->toHaveCount(4);

    $koshara = $data->firstWhere('slug_path', 'koshi/sunsari/koshara');

    expect($koshara)->not->toBeNull()
        ->and($koshara['wards'])->toHaveCount(20)
        ->and($koshara['wards'][0]['number'])->toBe(1)
        ->and($koshara['updated_at'])->not->toBeNull();
});

it('returns wards in order, so a sitemap is stable between builds', function (): void {
    $koshara = $this->getJson('/api/v1/published-paths')->collect('data')
        ->firstWhere('slug_path', 'koshi/sunsari/koshara');

    expect(array_column($koshara['wards'], 'number'))->toBe(range(1, 20));
});

it('omits a municipality whose tenant is not active', function (): void {
    /*
     * Listing it would have crawlers indexing a 503, and would say publicly
     * which municipalities are being prepared before anyone agreed they were
     * ready to be seen.
     */
    Tenant::query()
        ->firstWhere('admin_unit_id', DemoDataset::id('admin_unit', 'koshara'))
        ?->forceFill(['status' => TenantStatus::Maintenance])->save();

    $paths = $this->getJson('/api/v1/published-paths')->collect('data')->pluck('slug_path');

    expect($paths)->not->toContain('koshi/sunsari/koshara')->toHaveCount(3);
});

it('omits an unpublished ward', function (): void {
    // The ward exists in the data and has no page. A sitemap entry for it is a
    // promise of a 404.
    $ward = AdminUnit::query()
        ->where('level', 'ward')
        ->where('parent_id', DemoDataset::id('admin_unit', 'koshara'))
        ->where('ward_number', 20)
        ->firstOrFail();

    $ward->forceFill(['is_published' => false])->save();

    $koshara = $this->getJson('/api/v1/published-paths')->collect('data')
        ->firstWhere('slug_path', 'koshi/sunsari/koshara');

    expect($koshara['wards'])->toHaveCount(19)
        ->and(array_column($koshara['wards'], 'number'))->not->toContain(20);
});

it('is empty rather than broken when nothing is open yet', function (): void {
    Tenant::query()->update(['status' => TenantStatus::Maintenance->value]);

    $this->getJson('/api/v1/published-paths')->assertOk()->assertExactJson(['data' => []]);
});

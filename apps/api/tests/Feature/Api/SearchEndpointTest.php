<?php

declare(strict_types=1);

use App\Modules\Demo\Actions\SeedDemoData;
use App\Modules\Demo\Data\DemoDataset;
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

/**
 * @return list<array<string, mixed>>
 */
function search(string $q): array
{
    return test()->getJson('/api/v1/search?q='.urlencode($q))->assertOk()->json('data');
}

it('finds a municipality by its Nepali name', function (): void {
    $results = search('कोशारा');

    expect($results)->not->toBeEmpty()
        ->and($results[0]['type'])->toBe('local_level')
        ->and($results[0]['slug_path'])->toBe('koshi/sunsari/koshara');
});

it('finds a municipality by its English name', function (): void {
    expect(search('Koshara')[0]['slug_path'])->toBe('koshi/sunsari/koshara');
});

it('reads a trailing number as a ward, in Latin digits', function (): void {
    /*
     * The query people actually type. Nobody thinks "select the municipality,
     * then select the ward" — they think "Koshara 4" and type that.
     */
    $results = search('koshara 4');

    $ward = collect($results)->firstWhere('type', 'ward');

    expect($ward)->not->toBeNull()
        ->and($ward['ward_number'])->toBe(4)
        ->and($ward['slug_path'])->toBe('koshi/sunsari/koshara')
        // Above its own municipality: they asked for the ward.
        ->and($results[0]['type'])->toBe('ward');
});

it('reads a trailing number in Devanagari digits too', function (): void {
    // An office sign prints ४; a phone keyboard types 4. Both are the same ward.
    $ward = collect(search('कोशारा ४'))->firstWhere('type', 'ward');

    expect($ward)->not->toBeNull()->and($ward['ward_number'])->toBe(4);
});

it('keeps the municipality in the results alongside the ward', function (): void {
    // Somebody typing a number after a half-remembered name may have the name
    // slightly wrong; dropping the municipality row leaves nothing to correct
    // towards.
    $types = array_column(search('koshara 4'), 'type');

    expect($types)->toContain('ward')->toContain('local_level');
});

it('does not invent a ward the municipality does not have', function (): void {
    /*
     * Sainli has 7 wards. A search result leading to a 404 reads as the site
     * being broken, not as the ward not existing.
     */
    $results = search('sainli 40');

    expect(collect($results)->firstWhere('type', 'ward'))->toBeNull()
        ->and(collect($results)->firstWhere('type', 'local_level'))->not->toBeNull();
});

it('treats a number alone as no search at all', function (): void {
    // Otherwise "4" returns every ward 4 in the country, which is not an answer
    // to any question anyone asked.
    expect(search('4'))->toBe([]);
});

it('ignores a query too short to mean anything', function (): void {
    expect(search('क'))->toBe([]);
});

it('does not offer a municipality whose tenant is not active', function (): void {
    /*
     * The same rule as the picker. A result that leads to a 503 is worse than
     * no result: the visitor concludes the site is broken rather than that
     * their municipality is not ready.
     */
    Tenant::query()
        ->firstWhere('admin_unit_id', DemoDataset::id('admin_unit', 'koshara'))
        ?->forceFill(['status' => TenantStatus::Maintenance])->save();

    expect(collect(search('koshara'))->pluck('slug_path'))->not->toContain('koshi/sunsari/koshara');
});

it('carries enough context to tell two similar names apart', function (): void {
    // Nepal has repeated place names across districts, so a bare name in a
    // result list is not enough to choose from.
    $first = search('कोशारा')[0];

    expect($first['district']['ne'])->not->toBeNull()
        ->and($first['province']['ne'])->not->toBeNull()
        ->and($first['local_level_type'])->toBe('sub_metropolitan_city');
});

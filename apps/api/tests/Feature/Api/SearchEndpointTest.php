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

/**
 * @return list<string>
 */
function searchPaths(string $query): array
{
    $data = test()->getJson('/api/v1/search?q='.urlencode($query))->assertOk()->json('data');

    return array_map(
        fn (array $row): string => $row['ward_number'] === null
            ? $row['slug_path']
            : $row['slug_path'].'/'.$row['ward_number'],
        $data,
    );
}

it('finds a municipality by its Nepali name', function (): void {
    expect(searchPaths('कोशारा'))->toContain('koshi/sunsari/koshara');
});

it('finds a municipality by its English name', function (): void {
    expect(searchPaths('Koshara'))->toContain('koshi/sunsari/koshara');
});

it('finds a municipality from a near miss', function (): void {
    /*
     * The reason this is a trigram query and not a LIKE. Nepali place names
     * reach a search box romanized half a dozen ways, and a reader who types
     * the spelling on their own ward office sign should not get nothing.
     */
    expect(searchPaths('Koshaara'))->toContain('koshi/sunsari/koshara');
});

it('reads a trailing ward number and lands on the ward', function (): void {
    // "koshara 4" means ward 4 — the most specific thing in the query, and the
    // tap the whole product is about.
    expect(searchPaths('koshara 4'))->toContain('koshi/sunsari/koshara/4');
});

it('reads Devanagari digits as the same ward number', function (): void {
    // A ward number on an office sign is Devanagari; the same number typed on a
    // phone is usually Latin. Both are the same query.
    expect(searchPaths('कोशारा ४'))->toContain('koshi/sunsari/koshara/4');
});

it('falls back to the municipality when that ward is not published', function (): void {
    /*
     * Sonapur has 11 wards. Asking for its ward 40 must not invent a link to a
     * page that 404s — the municipality is the honest answer.
     */
    $results = searchPaths('sonapur 40');

    expect($results)->toContain('madhesh/rautahat/sonapur')
        ->and($results)->not->toContain('madhesh/rautahat/sonapur/40');
});

it('does not treat a leading number as a ward', function (): void {
    /*
     * Only a trailing number is a ward number. Guessing otherwise would answer
     * a different question from the one asked.
     *
     * This previously asserted nothing but a 200 and was marked
     * throwsNoExceptions(), so it passed whatever the endpoint decided to do
     * with a leading number — including routing straight to ward 4.
     */
    $results = searchPaths('4 koshara');

    expect($results)->toContain('koshi/sunsari/koshara')
        ->and($results)->not->toContain('koshi/sunsari/koshara/4');
});

it('still finds the municipality when a Devanagari number leads', function (): void {
    // "४ कोशारा" is how a reader who thinks in "४ नम्बर वडा" starts typing.
    // The number is not specific enough to route on, but the name is not in
    // doubt, and answering "we may not cover your municipality" would be
    // untrue.
    $results = searchPaths('४ कोशारा');

    expect($results)->toContain('koshi/sunsari/koshara')
        ->and($results)->not->toContain('koshi/sunsari/koshara/4');
});

it('returns nothing for an empty query rather than everything', function (): void {
    expect($this->getJson('/api/v1/search?q=')->assertOk()->json('data'))->toBe([]);
});

it('returns nothing rather than erroring on an absurd query', function (): void {
    $this->getJson('/api/v1/search?q='.str_repeat('क', 400))
        ->assertOk()
        ->assertJsonPath('meta.count', 0);
});

it('never offers a municipality whose tenant is not active', function (): void {
    // Same bar as the picker: a result that leads to a 503 reads as a broken
    // site, not as a municipality that is not ready.
    Tenant::query()->firstWhere('admin_unit_id', DemoDataset::id('admin_unit', 'koshara'))
        ?->forceFill(['status' => TenantStatus::Maintenance])->save();

    expect(searchPaths('कोशारा'))->not->toContain('koshi/sunsari/koshara');
});

it('never offers an unpublished municipality', function (): void {
    AdminUnit::query()->whereKey(DemoDataset::id('admin_unit', 'koshara'))
        ->update(['is_published' => false]);

    expect(searchPaths('Koshara'))->not->toContain('koshi/sunsari/koshara');
});

it('labels each hit as a municipality or a ward', function (): void {
    $municipality = $this->getJson('/api/v1/search?q=koshara')->assertOk()->json('data.0');
    expect($municipality['type'])->toBe('local_level')
        ->and($municipality['ward_number'])->toBeNull()
        ->and($municipality['district']['en'])->toBe('Sunsari')
        ->and($municipality['province'])->toHaveKeys(['ne', 'en']);

    $ward = $this->getJson('/api/v1/search?q=koshara%204')->assertOk()->json('data.0');
    expect($ward['type'])->toBe('ward')
        ->and($ward['ward_number'])->toBe(4)
        ->and($ward['district']['en'])->toBe('Sunsari');
});
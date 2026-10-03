<?php

declare(strict_types=1);

use App\Modules\Demo\Actions\SeedDemoData;
use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Person;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
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

const EVIDENCE_KOSHARA = 'koshi/sunsari/koshara';

/**
 * Reads a value out of one demonstration tenant.
 *
 * The tenant context is opened and closed explicitly rather than borrowed from
 * a prior request: ResolveTenant ends it in terminable middleware, which a test
 * request does not reliably run, so a test that leant on it would pass or fail
 * depending on what ran before it.
 *
 * @template T
 *
 * @param  Closure(): T  $read
 * @return T
 */
function insideDemoTenant(string $key, Closure $read): mixed
{
    $tenant = Tenant::query()->firstWhere('admin_unit_id', DemoDataset::id('admin_unit', $key));
    $tenancy = app(TenantManager::class);

    $tenancy->initialize($tenant);

    try {
        return $read();
    } finally {
        $tenancy->end();
    }
}

/** The ward chair whose party the dataset deliberately gives two sources for. */
function wardChairHolding(string $localLevelKey, int $wardNumber): OfficeHolding
{
    return insideDemoTenant($localLevelKey, fn (): OfficeHolding => OfficeHolding::query()
        ->where('position_key', 'ward_chair')
        ->whereIn(
            'constituency_id',
            TenantAdminUnit::query()->where('level', 'ward')->where('ward_number', $wardNumber)->select('id'),
        )
        ->firstOrFail());
}

it('shows the sources behind a verified seat', function (): void {
    $holding = wardChairHolding('koshara', 1);

    $response = $this
        ->getJson('/api/v1/evidence/'.EVIDENCE_KOSHARA."/office_holding/{$holding->id}")
        ->assertOk();

    expect($response->json('data.is_empty'))->toBeFalse()
        ->and($response->json('data.record'))->not->toBeEmpty()
        ->and($response->json('data.record.0.verification_status'))->toBe('verified')
        ->and($response->json('data.record.0.url'))->toStartWith('https://');
});

it('keeps both sides of a disagreement and orders them by authority', function (): void {
    /*
     * The case the whole trust model exists for. Two sources disagree about
     * which party the ward 1 chair was elected for: the municipality's own
     * record, and an unverified social-media claim. Neither may be dropped, and
     * the more authoritative one comes first (project instructions §4).
     */
    $holding = wardChairHolding('koshara', 1);

    $response = $this
        ->getJson('/api/v1/evidence/'.EVIDENCE_KOSHARA."/office_holding/{$holding->id}")
        ->assertOk();

    expect($response->json('data.has_conflict'))->toBeTrue();

    $party = $response->collect('data.fields')->firstWhere('field_path', 'party_id');

    expect($party)->not->toBeNull()
        ->and($party['in_conflict'])->toBeTrue()
        ->and($party['sources'])->toHaveCount(2);

    // Rank 1 is the Election Commission; the municipality's own record
    // outranks a social-media claim, and the order says so.
    $ranks = array_column(array_column($party['sources'], 'source_type'), 'authority_rank');
    expect($ranks[0])->toBeLessThan($ranks[1]);

    // The asserted values are what makes the disagreement legible: a page has
    // to be able to print "this source says A, that one says B".
    expect(array_unique(array_column($party['sources'], 'asserted_value')))->toHaveCount(2);

    // The weaker source is not quietly promoted on its way through.
    expect($party['sources'][1]['verification_status'])->toBe('unverified')
        ->and($party['sources'][1]['provenance_type'])->toBe('unverified_claim');
});

it('answers 200 with nothing rather than 404 when a record has no sources', function (): void {
    /*
     * Ward 3's seats carry names and no sources — the "not yet verified" state.
     * An error here would make the honest answer look like a broken page.
     */
    $holding = wardChairHolding('koshara', 3);

    $response = $this
        ->getJson('/api/v1/evidence/'.EVIDENCE_KOSHARA."/office_holding/{$holding->id}")
        ->assertOk();

    expect($response->json('data.is_empty'))->toBeTrue()
        ->and($response->json('data.has_conflict'))->toBeFalse()
        ->and($response->json('data.record'))->toBe([])
        ->and($response->json('data.fields'))->toBe([]);
});

it('refuses a subject type that is not on the allowlist', function (): void {
    // Caught by the route constraint, before any query runs.
    $this->getJson('/api/v1/evidence/'.EVIDENCE_KOSHARA.'/staff_user/'.fake()->uuid())
        ->assertNotFound();
});

it('refuses an id from another municipality', function (): void {
    /*
     * The isolation that matters. A holding id is a uuid, and uuids travel — in
     * a shared link, in a screenshot, in a scrape. Asking for one
     * municipality's holding through another's address must not work, or the
     * tenant boundary is decorative.
     */
    $koshara = wardChairHolding('koshara', 1);

    $this->getJson('/api/v1/evidence/bagmati/kathmandu/himtara/office_holding/'.$koshara->id)
        ->assertNotFound();
});

it('refuses a well-formed id that does not exist', function (): void {
    $this->getJson('/api/v1/evidence/'.EVIDENCE_KOSHARA.'/office_holding/'.fake()->uuid())
        ->assertNotFound();
});

it('serves evidence for a person record', function (): void {
    $person = wardChairHolding('koshara', 1)->person_id;

    $this->getJson('/api/v1/evidence/'.EVIDENCE_KOSHARA."/person/{$person}")
        ->assertOk()
        ->assertJsonPath('data.subject_type', 'person');
});

it('refuses a person through a municipality they hold no seat in', function (): void {
    // Public data, but the wrong address: Himtara's URL must not present a
    // Koshara councillor as though they were Himtara's.
    $person = wardChairHolding('koshara', 1)->person_id;

    $this->getJson("/api/v1/evidence/bagmati/kathmandu/himtara/person/{$person}")
        ->assertNotFound();
});

it('hides an unpublished person behind the same 404 as a missing one', function (): void {
    // "Exists but not published" has to be indistinguishable from "does not
    // exist", or this endpoint enumerates whatever is still being checked.
    // A Koshara seat-holder, so the only reason left for a 404 is publication.
    $person = Person::query()->findOrFail(wardChairHolding('koshara', 1)->person_id);
    $person->forceFill(['is_published' => false, 'published_at' => null])->save();

    $this->getJson('/api/v1/evidence/'.EVIDENCE_KOSHARA."/person/{$person->id}")
        ->assertNotFound();
});

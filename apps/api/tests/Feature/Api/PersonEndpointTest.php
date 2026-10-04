<?php

declare(strict_types=1);

use App\Modules\Demo\Actions\SeedDemoData;
use App\Modules\Offices\Models\Person;
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

const PERSON_KOSHARA = 'koshi/sunsari/koshara';

/** Whoever the demonstration dataset seated as Koshara's mayor. */
function kosharaMayorSlug(): string
{
    $seats = testCase()->getJson('/api/v1/local-levels/'.PERSON_KOSHARA)->collect('data.leadership');

    return $seats->firstWhere('position_key', 'mayor')['person']['slug'];
}

it('returns a person with the seats they hold here', function (): void {
    $response = $this
        ->getJson('/api/v1/persons/'.PERSON_KOSHARA.'/'.kosharaMayorSlug())
        ->assertOk();

    expect($response->json('data.name.ne'))->not->toBeEmpty()
        ->and($response->json('data.seats'))->not->toBeEmpty()
        ->and($response->json('data.seats.0.position_key'))->toBe('mayor')
        ->and($response->json('data.seats.0.state'))->toBe('held')
        ->and($response->json('data.local_level.name.ne'))->toBe('कोशारा उपमहानगरपालिका');
});

it('carries the address of its own evidence', function (): void {
    // Evidence that this name is this person, which is a separate claim from
    // evidence that they hold a seat. Conflating them would let a verified
    // holding imply a verified identity.
    $response = $this->getJson('/api/v1/persons/'.PERSON_KOSHARA.'/'.kosharaMayorSlug())->assertOk();

    expect($response->json('data.evidence.subject_type'))->toBe('person')
        ->and($response->json('data.evidence.subject_id'))->not->toBeEmpty();
});

it('gives every seat an evidence address so the badge can lead somewhere', function (): void {
    /*
     * The gap this feature closes. A held seat used to carry a badge reading
     * "official source" that linked to an anchor on the same page.
     */
    $response = $this->getJson('/api/v1/persons/'.PERSON_KOSHARA.'/'.kosharaMayorSlug())->assertOk();

    $seat = $response->json('data.seats.0');

    expect($seat['evidence'])->not->toBeNull()
        ->and($seat['evidence']['subject_type'])->toBe('office_holding');
});

it('does not serve a person who holds nothing in this municipality', function (): void {
    /*
     * A person page headed with a municipality's name, showing someone who
     * holds no seat in it, invites exactly the wrong inference. Himtara's
     * mayor is a real published person — just not one of Koshara's.
     */
    $himtaraMayor = $this->getJson('/api/v1/local-levels/bagmati/kathmandu/himtara')->collect('data.leadership')
        ->firstWhere('position_key', 'mayor')['person']['slug'];

    $this->getJson('/api/v1/persons/'.PERSON_KOSHARA.'/'.$himtaraMayor)->assertNotFound();
});

it('hides an unpublished person', function (): void {
    $slug = kosharaMayorSlug();

    Person::query()->where('slug', $slug)->update(['is_published' => false]);

    $this->getJson('/api/v1/persons/'.PERSON_KOSHARA.'/'.$slug)->assertNotFound();
});

it('404s an unknown slug', function (): void {
    $this->getJson('/api/v1/persons/'.PERSON_KOSHARA.'/not-a-real-person')->assertNotFound();
});

it('is not reachable for an unpublished municipality', function (): void {
    $this->getJson('/api/v1/persons/koshi/sunsari/nowhere/'.kosharaMayorSlug())->assertNotFound();
});

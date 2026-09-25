<?php

declare(strict_types=1);

use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Models\PersonMerge;
use App\Modules\Offices\Support\PersonSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('holds no attribute that could profile a person', function (string $column): void {
    // R9, FR-OFF-05. The absence is the feature; a test stops it being added
    // back by someone who needs "just one more field".
    expect(Schema::connection('central')->hasColumn('persons', $column))->toBeFalse();
})->with(['gender', 'caste', 'ethnicity', 'religion', 'date_of_birth', 'dob', 'address', 'home_address']);

it('gives parties no colour', function (): void {
    // NFR-NEU-02: party colours turn every list into a scoreboard.
    expect(Schema::connection('central')->hasColumn('parties', 'colour'))->toBeFalse()
        ->and(Schema::connection('central')->hasColumn('parties', 'color'))->toBeFalse();
});

it('needs at least one name in one script', function (): void {
    expectRejectedByDatabase(fn () => Person::query()->create([
        'slug' => 'nameless-ab12',
    ]));
});

it('keeps a person unpublished until someone publishes them', function (): void {
    expect(Person::factory()->create()->is_published)->toBeFalse();
});

it('refuses a published person with no publication timestamp', function (): void {
    expectRejectedByDatabase(fn () => Person::query()->create([
        'slug' => 'ram-thapa-k7m2',
        'full_name_en' => 'Ram Thapa',
        'is_published' => true,
    ]));
});

it('gives two people with the same name different URLs', function (): void {
    $first = Person::factory()->create(['full_name_en' => 'Ram Bahadur Thapa']);
    $second = Person::factory()->create(['full_name_en' => 'Ram Bahadur Thapa']);

    expect($first->slug)->not->toBe($second->slug)
        ->and($first->slug)->toStartWith('ram-bahadur-thapa-');
});

it('falls back to a neutral slug when there is no Latin name', function (): void {
    $person = Person::factory()->devanagariOnly()->create(['full_name_ne' => 'राम बहादुर थापा']);

    expect($person->slug)->toStartWith('vyakti-')
        ->and($person->full_name_en)->toBeNull()
        ->and($person->displayName())->toBe('राम बहादुर थापा');
});

it('never produces a slug the database would reject', function (): void {
    foreach (['Ram Bahadur Thapa', 'राम थापा', '  ', '!!!', 'Ram-Bahadur  Thapa'] as $name) {
        expect(PersonSlug::for($name))->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');
    }
});

it('prefers Nepali for display and falls back to English', function (): void {
    $person = Person::factory()->create([
        'full_name_ne' => 'हेमन्ती घिमिरे',
        'full_name_en' => 'Hemanti Ghimire',
    ]);

    expect($person->displayName())->toBe('हेमन्ती घिमिरे')
        ->and($person->displayName('en'))->toBe('Hemanti Ghimire');
});

it('hides a merged person from public listings but keeps the row', function (): void {
    $kept = Person::factory()->published()->create();
    $duplicate = Person::factory()->mergedInto($kept)->create();

    expect(Person::query()->publiclyVisible()->pluck('id')->all())->toBe([$kept->id])
        ->and(Person::query()->find($duplicate->id))->not->toBeNull()
        ->and($duplicate->isMerged())->toBeTrue();
});

it('refuses to publish a person who has been merged away', function (): void {
    $kept = Person::factory()->create();

    expectRejectedByDatabase(fn () => Person::factory()->create([
        'merged_into_person_id' => $kept->id,
        'is_published' => true,
        'published_at' => now(),
    ]));
});

it('requires two different approvers for a merge', function (): void {
    // D-002: the same rule as source verification. Nepali names repeat, and one
    // person's judgement is not enough to fuse two records.
    $kept = Person::factory()->create();
    $duplicate = Person::factory()->create();
    $approver = (string) Illuminate\Support\Str::uuid();

    expectRejectedByDatabase(fn () => PersonMerge::query()->create([
        'kept_person_id' => $kept->id,
        'merged_person_id' => $duplicate->id,
        'reason' => 'Same ward chair, two spellings',
        'approved_by' => $approver,
        'second_approved_by' => $approver,
    ]));
});

it('records a merge once both approvers have signed', function (): void {
    $kept = Person::factory()->create();
    $duplicate = Person::factory()->create();

    $merge = PersonMerge::query()->create([
        'kept_person_id' => $kept->id,
        'merged_person_id' => $duplicate->id,
        'reason' => 'Same ward chair, two spellings',
        'approved_by' => (string) Illuminate\Support\Str::uuid(),
        'second_approved_by' => (string) Illuminate\Support\Str::uuid(),
    ]);

    expect($merge->isFullyApproved())->toBeTrue()
        ->and($merge->keptPerson->id)->toBe($kept->id);
});

it('lets a dissolved party keep its slug free for nobody else while current', function (): void {
    Party::factory()->create(['slug' => 'example-party']);

    expectRejectedByDatabase(fn () => Party::factory()->create(['slug' => 'example-party']));
});

it('keeps a dissolved party out of current listings but available to history', function (): void {
    $dissolved = Party::factory()->dissolved()->create();
    $current = Party::factory()->create();

    expect(Party::query()->current()->pluck('id')->all())->toBe([$current->id])
        ->and(Party::query()->find($dissolved->id))->not->toBeNull();
});

it('falls back between scripts for a party name and abbreviation', function (): void {
    $party = Party::factory()->create([
        'name_ne' => 'उदाहरण दल',
        'name_en' => null,
        'abbreviation_ne' => 'उ.द.',
        'abbreviation_en' => null,
    ]);

    expect($party->displayName('en'))->toBe('उदाहरण दल')
        ->and($party->abbreviation('en'))->toBe('उ.द.');
});

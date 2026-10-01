<?php

declare(strict_types=1);

use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Provenance\Enums\ProvenanceType;
use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Enums\VerificationStatus;
use App\Modules\Provenance\Models\Source;
use App\Modules\Provenance\Models\SourceLink;
use App\Modules\Provenance\Models\SourceType;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('seeds the source hierarchy with unique ranks, highest authority first', function (): void {
    $this->seed(SourceTypeSeeder::class);

    $ranked = SourceType::query()->orderBy('authority_rank')->pluck('key')->all();

    expect($ranked)->toHaveCount(10)
        ->and($ranked[0])->toBe(SourceTypeKey::Ecn->value)
        ->and(end($ranked))->toBe(SourceTypeKey::SocialMedia->value)
        // Builder::value() applies the model's casts, so this is the enum
        // case itself and not its backing string.
        ->and(SourceType::query()->where('key', SourceTypeKey::SocialMedia->value)->value('default_provenance_type'))
        ->toBe(ProvenanceType::UnverifiedClaim);
});

it('requires a link or an uploaded document on every source', function (): void {
    expectRejectedByDatabase(fn () => Source::factory()->create(['url' => null]));

    expectRejectedByDatabase(fn () => Source::factory()->create(['url' => 'ftp://example.test/file']));

    $document = Source::factory()->document()->create();

    expect($document->url)->toBeNull()
        ->and($document->document_media_id)->not->toBeNull();
});

it('attaches evidence to a record or to a single field', function (): void {
    $unit = AdminUnit::factory()->localLevel()->create();
    $source = Source::factory()->create();

    $record = $unit->sourceLinks()->create([
        'source_id' => $source->id,
        'provenance_type' => ProvenanceType::Official,
        'locator' => 'Annex 2, row 14',
    ]);

    $field = $unit->sourceLinks()->create([
        'source_id' => $source->id,
        'field_path' => 'name_ne',
        'provenance_type' => ProvenanceType::Official,
        'asserted_value' => 'नमुना नगरपालिका',
    ]);

    expect($record->subject_type)->toBe('admin_unit')
        ->and($record->subject->is($unit))->toBeTrue()
        ->and($field->asserted_value)->toBe('नमुना नगरपालिका')
        ->and(SourceLink::query()->forSubject($unit)->count())->toBe(1)
        ->and(SourceLink::query()->forSubject($unit, 'name_ne')->count())->toBe(1);
});

it('only accepts subject types that live in this database', function (): void {
    $source = Source::factory()->create();

    expectRejectedByDatabase(fn () => SourceLink::query()->create([
        'source_id' => $source->id,
        'subject_type' => 'issue', // tenant subject
        'subject_id' => (string) Str::uuid(),
        'provenance_type' => ProvenanceType::CommunityReport,
    ]));
});

it('keeps verification honest', function (): void {
    $unit = AdminUnit::factory()->localLevel()->create();
    $link = $unit->sourceLinks()->create([
        'source_id' => Source::factory()->create()->id,
        'provenance_type' => ProvenanceType::Official,
    ]);

    expect($link->verification_status)->toBe(VerificationStatus::Unverified)
        ->and($link->isVerified())->toBeFalse();

    // verified without a verifier or a timestamp
    expectRejectedByDatabase(fn () => SourceLink::query()->whereKey($link->id)
        ->update(['verification_status' => VerificationStatus::Verified->value]));

    $verifier = (string) Str::uuid();

    SourceLink::query()->whereKey($link->id)->update([
        'verification_status' => VerificationStatus::Verified->value,
        'verified_by' => $verifier,
        'verified_at' => now(),
    ]);

    expect($link->refresh()->isVerified())->toBeTrue()
        ->and($unit->verifiedSourceLinks()->count())->toBe(1);

    // the second approver must be a different person (D-002)
    expectRejectedByDatabase(fn () => SourceLink::query()->whereKey($link->id)
        ->update(['second_approved_by' => $verifier]));
});

it('orders sources by authority then recency', function (): void {
    $this->seed(SourceTypeSeeder::class);

    Source::factory()->ofType(SourceTypeKey::SocialMedia)->create(['title' => 'a post', 'published_at' => '2026-09-01']);
    Source::factory()->ofType(SourceTypeKey::Media)->create(['title' => 'a report', 'published_at' => '2024-01-01']);
    Source::factory()->ofType(SourceTypeKey::Ecn)->create(['title' => 'official results', 'published_at' => '2022-06-01']);

    expect(Source::query()->byAuthority()->pluck('title')->all())
        ->toBe(['official results', 'a report', 'a post']);
});

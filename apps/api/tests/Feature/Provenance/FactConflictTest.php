<?php

declare(strict_types=1);

use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Provenance\Enums\ConflictStatus;
use App\Modules\Provenance\Enums\ProvenanceType;
use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Models\FactConflict;
use App\Modules\Provenance\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps both values when sources disagree', function (): void {
    $unit = AdminUnit::factory()->localLevel()->create();

    $census = $unit->sourceLinks()->create([
        'source_id' => Source::factory()->ofType(SourceTypeKey::GovernmentOfNepal)->create(['published_at' => '2021-11-01'])->id,
        'field_path' => 'population',
        'provenance_type' => ProvenanceType::Official,
        'asserted_value' => 198098,
    ]);

    $localSite = $unit->sourceLinks()->create([
        'source_id' => Source::factory()->ofType(SourceTypeKey::LocalLevel)->create(['published_at' => '2018-05-01'])->id,
        'field_path' => 'population',
        'provenance_type' => ProvenanceType::Official,
        'asserted_value' => 197241,
    ]);

    $conflict = FactConflict::query()->create([
        'subject_type' => $unit->getMorphClass(),
        'subject_id' => $unit->id,
        'field_path' => 'population',
        'blocks_publication' => true,
    ]);

    $conflict->sourceLinks()->attach([$census->id, $localSite->id]);

    expect($conflict->isOpen())->toBeTrue()
        ->and($conflict->status)->toBe(ConflictStatus::Open)
        ->and($conflict->sourceLinks()->pluck('asserted_value')->map(fn (mixed $v): int => (int) json_decode((string) $v, true))->all())
        ->toEqualCanonicalizing([198098, 197241]);
});

it('never marks a conflict resolved without a reason', function (): void {
    $unit = AdminUnit::factory()->localLevel()->create();

    $conflict = FactConflict::query()->create([
        'subject_type' => $unit->getMorphClass(),
        'subject_id' => $unit->id,
        'field_path' => 'population',
    ]);

    expectRejectedByDatabase(fn () => FactConflict::query()->whereKey($conflict->id)
        ->update(['status' => ConflictStatus::Resolved->value]));

    FactConflict::query()->whereKey($conflict->id)->update([
        'status' => ConflictStatus::Resolved->value,
        'resolution' => 'Census 2021 is newer and higher authority.',
        'resolved_value' => json_encode(198098, JSON_THROW_ON_ERROR),
        'resolved_at' => now(),
    ]);

    expect($conflict->refresh()->status)->toBe(ConflictStatus::Resolved);
});

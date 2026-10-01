<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Provenance\Enums\ProvenanceType;
use App\Modules\Provenance\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Connects one source to the fact it supports (docs/05 §4.3).
 *
 * `field_path` null means the whole record; otherwise the single field this
 * source backs, e.g. "party_id" or "end_date". `asserted_value` records what
 * the source actually says, which is what conflicts compare.
 *
 * @property string $id
 * @property string $source_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $field_path
 * @property ProvenanceType $provenance_type
 * @property mixed $asserted_value
 * @property string|null $locator
 * @property string|null $excerpt
 * @property VerificationStatus $verification_status
 * @property string|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $second_approved_by
 * @property string|null $evidence_note
 */
abstract class BaseSourceLink extends Model
{
    use HasUuids;

    protected $table = 'source_links';

    /**
     * The column defaults to 'unverified' in the database, but a model built
     * in PHP does not learn that until it is reloaded — so a link read back
     * straight after create() had a null verification status: neither
     * verified nor unverified, just undefined. A piece of evidence is never
     * allowed to be in no state at all (docs/05 §6), so the model states the
     * same default the column does. The database remains the enforcement.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'verification_status' => 'unverified',
    ];

    /**
     * Verification is applied through the verification workflow (HW-E17-F02),
     * never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'subject_type',
        'subject_id',
        'field_path',
        'provenance_type',
        'asserted_value',
        'locator',
        'excerpt',
        'evidence_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provenance_type' => ProvenanceType::class,
            'verification_status' => VerificationStatus::class,
            'asserted_value' => 'json',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(type: 'subject_type', id: 'subject_id');
    }

    /**
     * @param  Builder<covariant BaseSourceLink>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->where('verification_status', VerificationStatus::Verified->value);
    }

    /**
     * @param  Builder<covariant BaseSourceLink>  $query
     */
    public function scopeForSubject(Builder $query, Model $subject, ?string $fieldPath = null): void
    {
        $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->when(
                $fieldPath === null,
                fn (Builder $inner) => $inner->whereNull('field_path'),
                fn (Builder $inner) => $inner->where('field_path', $fieldPath),
            );
    }

    public function isVerified(): bool
    {
        return $this->verification_status === VerificationStatus::Verified;
    }
}

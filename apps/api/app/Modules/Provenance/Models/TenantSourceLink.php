<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Provenance\Enums\SourceScope;
use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence for this local level's facts. The source is either local
 * (`source_scope = tenant`, enforced by a trigger) or a national one in the
 * central database (`source_scope = central`, validated by the application and
 * the nightly consistency check — docs/12 §4.1).
 *
 * @property SourceScope $source_scope
 */
final class TenantSourceLink extends BaseSourceLink
{
    use UsesTenantConnection;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_scope',
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
     * Redeclaring $attributes REPLACES the parent's rather than merging with
     * it, so BaseSourceLink's verification_status default has to be repeated
     * here or tenant links come up with a null verification status — on the
     * side of the system that holds ward-page evidence, which is the side
     * where it matters most. Any default added to the parent belongs here too.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'source_scope' => 'tenant',
        'verification_status' => 'unverified',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [...parent::casts(), 'source_scope' => SourceScope::class];
    }

    /**
     * Local source only. Central-scope links resolve through centralSource().
     *
     * @return BelongsTo<TenantSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TenantSource::class, 'source_id');
    }

    public function centralSource(): ?Source
    {
        if ($this->source_scope !== SourceScope::Central) {
            return null;
        }

        return Source::query()->find($this->source_id);
    }

    public function resolveSource(): ?BaseSource
    {
        return $this->source_scope === SourceScope::Central
            ? $this->centralSource()
            : $this->source()->first();
    }
}

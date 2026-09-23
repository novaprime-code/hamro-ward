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
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source_scope' => 'tenant',
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

    public function resolveSource(): BaseSource|null
    {
        return $this->source_scope === SourceScope::Central
            ? $this->centralSource()
            : $this->source()->first();
    }
}

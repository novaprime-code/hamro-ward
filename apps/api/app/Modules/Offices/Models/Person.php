<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Provenance\Models\Concerns\HasSourceLinks;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A human being who holds, held or is standing for local office
 * (docs/05 §5.2, R9, FR-OFF-05).
 *
 * Central, because the same person appears in more than one municipality's
 * story over a career and de-duplication has to happen somewhere.
 *
 * What this model does NOT hold is the point of it: no gender, caste,
 * ethnicity, religion, date of birth or home address. A civic directory needs
 * none of them to say who holds a seat, and a database that has them is one
 * subpoena or one breach away from being used for something else.
 *
 * @property string $id
 * @property string $slug
 * @property string|null $full_name_ne
 * @property string|null $full_name_en
 * @property bool $is_published
 */
final class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use HasSourceLinks;
    use HasUuids;
    use UsesCentralConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<PersonAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(PersonAlias::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_person_id');
    }

    /**
     * Either name, preferring Nepali — the platform's primary language (§16).
     */
    public function displayName(string $locale = 'ne'): string
    {
        return $locale === 'en'
            ? (string) ($this->full_name_en ?? $this->full_name_ne)
            : (string) ($this->full_name_ne ?? $this->full_name_en);
    }

    public function isMerged(): bool
    {
        return $this->merged_into_person_id !== null;
    }

    /** Published and not merged away. Merged rows survive so old links keep resolving. */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereNull('merged_into_person_id');
    }

    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }
}

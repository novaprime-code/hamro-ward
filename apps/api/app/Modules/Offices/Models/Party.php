<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Provenance\Models\Concerns\HasSourceLinks;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A political party (docs/05 §5.3, NFR-NEU-02).
 *
 * There is no colour column and there will not be one. Parties rendered in
 * their own colours turn every list into a scoreboard and every page into
 * something that looks like it is campaigning; neutrality would then depend on
 * a palette. Party identity is carried by name and abbreviation, in text.
 *
 * Election symbols arrive in v0.6 with their own usage-rights field, because an
 * image we are not licensed to show is worse than no image.
 *
 * @property string $id
 * @property string $slug
 */
final class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    use HasSourceLinks;
    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'parties';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'immutable_datetime',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<PartyAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(PartyAlias::class);
    }

    public function displayName(string $locale = 'ne'): string
    {
        return $locale === 'en'
            ? (string) ($this->name_en ?? $this->name_ne)
            : (string) ($this->name_ne ?? $this->name_en);
    }

    public function abbreviation(string $locale = 'ne'): ?string
    {
        return $locale === 'en'
            ? ($this->abbreviation_en ?? $this->abbreviation_ne)
            : ($this->abbreviation_ne ?? $this->abbreviation_en);
    }

    /** Parties that still exist. A dissolved party stays for historical holdings. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('valid_to');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    protected static function newFactory(): PartyFactory
    {
        return PartyFactory::new();
    }
}

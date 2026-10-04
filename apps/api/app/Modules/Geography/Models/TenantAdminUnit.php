<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Geography\Casts\PostgresUuidArray;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * This tenant's slice of the administrative hierarchy (docs/12 §9).
 *
 * A read-only replica of the central admin_units rows on one path: the country,
 * the province, the district, this local level and its wards. Nothing else. A
 * municipality's database never learns the names of the other 752, and a bug in
 * one tenant cannot leak a national table.
 *
 * It exists because PostgreSQL cannot join across databases, and every tenant
 * query about issues, holdings and ward offices needs to filter by ward.
 *
 * All writes come from SyncTenantReferenceData; hw_app holds SELECT only. The
 * structural rules — parent levels, ward numbering, publication — are enforced
 * on the central table, so there are no triggers here to reject a replication.
 *
 * @property string $id
 * @property AdminLevel $level
 * @property array<int, string> $ancestor_ids
 */
final class TenantAdminUnit extends Model
{
    use UsesTenantConnection;

    public $timestamps = false;

    protected $table = 'admin_units';

    /**
     * The primary key is a uuid, and saying so is not optional.
     *
     * Eloquent's getCasts() does this:
     *
     *     if ($this->getIncrementing()) {
     *         return array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts);
     *     }
     *
     * Both defaults apply unless a model overrides them — $incrementing is
     * true and $keyType is 'int' — so without these two lines every read of
     * $unit->id returns (int) '81014cad-5f3a-…', which PHP evaluates to 81014,
     * or to 0 when the uuid happens to start with a letter. No error, no
     * warning: just a number where an identifier should be.
     *
     * The models that write their own ids get this from the HasUuids trait.
     * This one is a replica — ids arrive from central and are never generated
     * here — so it states the two facts directly instead.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'level' => AdminLevel::class,
            'local_level_type' => LocalLevelType::class,
            'ward_number' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'immutable_datetime',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'synced_at' => 'immutable_datetime',
            'ancestor_ids' => PostgresUuidArray::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<TenantAdminUnit, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<TenantAdminUnit, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isCurrent(): bool
    {
        return $this->valid_to === null;
    }

    public function displayName(string $locale = 'ne'): string
    {
        return $locale === 'en'
            ? (string) ($this->name_en ?? $this->name_ne)
            : (string) ($this->name_ne ?? $this->name_en);
    }

    /**
     * The local level this tenant is. Exactly one row, which is what makes the
     * tenant a tenant.
     */
    public static function localLevel(): ?self
    {
        return self::query()->where('level', AdminLevel::LocalLevel->value)->first();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('valid_to');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWards(Builder $query): Builder
    {
        return $query->where('level', AdminLevel::Ward->value)->orderBy('ward_number');
    }

    /**
     * Published and current. The central publication rule already checked that
     * every ancestor is published before a row could be published there, so the
     * replica does not re-derive it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereNull('valid_to');
    }
}

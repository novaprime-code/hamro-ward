<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Geography\Casts\PostgresUuidArray;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Provenance\Models\Concerns\HasSourceLinks;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Database\Factories\AdminUnitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Country, province, district, local level or ward (docs/05 §3.1).
 *
 * Structure is immutable: a unit's level and parent never change. Restructures,
 * merges and splits close the old unit (valid_to) and open a new one, linked in
 * admin_unit_lineage (R3). The database enforces this with a trigger.
 *
 * @property string $id
 * @property AdminLevel $level
 * @property string|null $parent_id
 * @property list<string> $ancestor_ids root-first, maintained by the database
 * @property LocalLevelType|null $local_level_type
 * @property int|null $ward_number
 * @property string $slug
 * @property string|null $name_ne
 * @property string|null $name_en
 * @property bool $is_published
 * @property Carbon|null $published_at
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property Carbon|null $created_at nullable columns: rows written by SQL rather than Eloquent have none
 * @property Carbon|null $updated_at
 */
final class AdminUnit extends Model
{
    /** @use HasFactory<AdminUnitFactory> */
    use HasFactory;

    use HasSourceLinks;
    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'admin_units';

    /**
     * Publication goes through PublishAdminUnit; ancestor_ids is owned by the database.
     *
     * @var list<string>
     */
    protected $fillable = [
        'level',
        'parent_id',
        'local_level_type',
        'ward_number',
        'slug',
        'name_ne',
        'name_en',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => AdminLevel::class,
            'local_level_type' => LocalLevelType::class,
            'ward_number' => 'integer',
            'ancestor_ids' => PostgresUuidArray::class,
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<AdminUnit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<AdminUnit, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<AdminUnitName, $this>
     */
    public function names(): HasMany
    {
        return $this->hasMany(AdminUnitName::class);
    }

    /**
     * @return HasMany<AdminUnitSlug, $this>
     */
    public function slugPaths(): HasMany
    {
        return $this->hasMany(AdminUnitSlug::class);
    }

    /**
     * @return HasOne<AdminUnitSlug, $this>
     */
    public function currentSlugPath(): HasOne
    {
        return $this->hasOne(AdminUnitSlug::class)->where('is_current', true);
    }

    /**
     * @return HasMany<AdminUnitAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(AdminUnitAlias::class);
    }

    /**
     * @return HasMany<AdminUnitCode, $this>
     */
    public function codes(): HasMany
    {
        return $this->hasMany(AdminUnitCode::class);
    }

    /**
     * Ancestors ordered root-first (country … parent).
     *
     * @return Collection<int, AdminUnit>
     */
    public function ancestors(): Collection
    {
        $ids = $this->ancestorIds();

        if ($ids === []) {
            return new Collection;
        }

        $order = array_flip($ids);

        return self::query()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (AdminUnit $unit): int => $order[$unit->id])
            ->values();
    }

    /**
     * ancestor_ids is written by the database trigger, so a model that was just
     * created in PHP does not have it yet. Load it on first use instead of
     * silently treating the unit as having no ancestors.
     *
     * @return list<string>
     */
    public function ancestorIds(): array
    {
        if ($this->exists && ! array_key_exists('ancestor_ids', $this->getAttributes())) {
            /*
             * Builder::value() runs the result through the model's casts, so
             * this is already a list<string> — not the raw "{a,b}" the column
             * holds. Feeding it back through setRawAttributes() would hand the
             * cast an array to parse as a string, and PostgresUuidArray would
             * return an empty list: the unit would look like it had no
             * ancestors, which is the exact failure this method exists to
             * prevent. setAttribute() applies the cast in the right direction.
             */
            /** @var list<string> $ids */
            $ids = self::query()->whereKey($this->getKey())->value('ancestor_ids') ?? [];

            $this->setAttribute('ancestor_ids', $ids);

            /*
             * The column is maintained by the admin_units_hierarchy trigger.
             * Loading it must not make the model dirty, or the next save()
             * would write a database-owned column back from PHP.
             */
            $this->syncOriginalAttribute('ancestor_ids');
        }

        return $this->ancestor_ids;
    }

    /**
     * @param  Builder<AdminUnit>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('admin_units.valid_to');
    }

    /**
     * @param  Builder<AdminUnit>  $query
     */
    public function scopeOfLevel(Builder $query, AdminLevel $level): void
    {
        $query->where('admin_units.level', $level->value);
    }

    /**
     * Publication rule (docs/05 §3.1, FR-GEO-04): a unit is publicly visible
     * only if it and every ancestor are published and current.
     *
     * @param  Builder<AdminUnit>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query
            ->where('admin_units.is_published', true)
            ->whereNull('admin_units.valid_to')
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('admin_units as ancestor')
                    ->whereRaw('ancestor.id = any(admin_units.ancestor_ids)')
                    ->where(function (QueryBuilder $hidden): void {
                        $hidden->where('ancestor.is_published', false)
                            ->orWhereNotNull('ancestor.valid_to');
                    });
            });
    }

    public function isCurrent(): bool
    {
        return $this->valid_to === null;
    }

    public function isPubliclyVisible(): bool
    {
        return self::query()->whereKey($this->getKey())->publiclyVisible()->exists();
    }

    protected static function newFactory(): AdminUnitFactory
    {
        return AdminUnitFactory::new();
    }
}

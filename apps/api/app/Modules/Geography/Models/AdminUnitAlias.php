<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Geography\Enums\AliasKind;
use App\Modules\Geography\Enums\AliasScript;
use App\Modules\Geography\Support\NameNormalizer;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alternative names used for search (FR-GEO-07). `normalized` is computed on save.
 *
 * @property string $id
 * @property string $admin_unit_id
 * @property string $alias
 * @property AliasScript $script
 * @property AliasKind $kind
 * @property string $normalized
 */
final class AdminUnitAlias extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'admin_unit_aliases';

    /**
     * @var list<string>
     */
    protected $fillable = ['admin_unit_id', 'alias', 'script', 'kind'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'script' => AliasScript::class,
            'kind' => AliasKind::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AdminUnitAlias $alias): void {
            $alias->normalized = NameNormalizer::normalize($alias->alias);
        });
    }

    /**
     * @return BelongsTo<AdminUnit, $this>
     */
    public function adminUnit(): BelongsTo
    {
        return $this->belongsTo(AdminUnit::class);
    }
}

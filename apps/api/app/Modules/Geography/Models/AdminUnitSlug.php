<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Full slug paths (e.g. "koshi/sunsari/namuna/4") for URL resolution and
 * permanent redirects of old paths (FR-GEO-03, FR-GEO-05).
 *
 * @property string $id
 * @property string $admin_unit_id
 * @property string $slug_path
 * @property bool $is_current
 */
final class AdminUnitSlug extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'admin_unit_slugs';

    /**
     * @var list<string>
     */
    protected $fillable = ['admin_unit_id', 'slug_path', 'is_current'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }

    /**
     * @return BelongsTo<AdminUnit, $this>
     */
    public function adminUnit(): BelongsTo
    {
        return $this->belongsTo(AdminUnit::class);
    }
}

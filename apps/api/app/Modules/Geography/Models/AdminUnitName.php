<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Name history (docs/05 §3.2). The current names are denormalized on admin_units.
 *
 * @property string $id
 * @property string $admin_unit_id
 * @property string|null $name_ne
 * @property string|null $name_en
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
final class AdminUnitName extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'admin_unit_names';

    /**
     * @var list<string>
     */
    protected $fillable = ['admin_unit_id', 'name_ne', 'name_en', 'valid_from', 'valid_to'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date'];
    }

    /**
     * @return BelongsTo<AdminUnit, $this>
     */
    public function adminUnit(): BelongsTo
    {
        return $this->belongsTo(AdminUnit::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Geography\Enums\LineageEvent;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Predecessor → successor links for restructures, merges, splits and renames (R3).
 *
 * @property string $id
 * @property string $predecessor_id
 * @property string $successor_id
 * @property LineageEvent $event
 * @property Carbon $effective_date
 * @property string|null $note
 */
final class AdminUnitLineage extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'admin_unit_lineage';

    /**
     * @var list<string>
     */
    protected $fillable = ['predecessor_id', 'successor_id', 'event', 'effective_date', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => LineageEvent::class,
            'effective_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<AdminUnit, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(AdminUnit::class, 'predecessor_id');
    }

    /**
     * @return BelongsTo<AdminUnit, $this>
     */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(AdminUnit::class, 'successor_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Offices\Enums\VacancyReason;
use App\Modules\Provenance\Models\Concerns\HasSourceLinks;
use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A seat recorded as empty over a period (docs/05 §5.5, docs/02 §4.4).
 *
 * A vacancy is a positive claim about the world and needs evidence like any
 * other. Without a verified source the seat reads not_verified, not vacant:
 * "nobody has filled this in" and "nobody holds this office" are different
 * statements and the platform must not conflate them.
 *
 * @property string $id
 * @property string $position_key
 * @property string $constituency_id
 * @property int $seat_index
 */
final class Vacancy extends Model
{
    use HasSourceLinks;
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'vacancies';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'seat_index' => 'integer',
            'vacant_from' => 'date',
            'vacant_to' => 'date',
            'reason' => VacancyReason::class,
        ];
    }

    /** @return BelongsTo<TenantPosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(TenantPosition::class, 'position_key', 'key');
    }

    /** @return BelongsTo<TenantAdminUnit, $this> */
    public function constituency(): BelongsTo
    {
        return $this->belongsTo(TenantAdminUnit::class, 'constituency_id');
    }

    public function scopeCurrentOn(Builder $query, ?Carbon $on = null): Builder
    {
        $on ??= Carbon::today();

        return $query->whereDate('vacant_from', '<=', $on)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('vacant_to')
                ->orWhereDate('vacant_to', '>', $on));
    }

    public function scopeForSeat(
        Builder $query,
        string $positionKey,
        string $constituencyId,
        int $seatIndex = 1,
    ): Builder {
        return $query->where('position_key', $positionKey)
            ->where('constituency_id', $constituencyId)
            ->where('seat_index', $seatIndex);
    }
}

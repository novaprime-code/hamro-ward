<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Offices\Enums\HoldingEndReason;
use App\Modules\Provenance\Models\Concerns\HasSourceLinks;
use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person holding one seat over one period (docs/05 §5.4, R12).
 *
 * Tenant-resident: a municipality's editors maintain its own representatives,
 * and one municipality's data problem never becomes another's.
 *
 * person() and party() cross a database boundary (D-014). Eloquent handles that
 * — each relation queries the central connection the related model declares —
 * but a SQL join across them is impossible, so anything that needs names for a
 * list of holdings goes through CurrentSeatsQuery, which batches the lookup.
 *
 * @property string $id
 * @property string $person_id
 * @property string $position_key
 * @property string $constituency_id
 * @property int $seat_index
 */
final class OfficeHolding extends Model
{
    use HasSourceLinks;
    use HasUuids;
    use UsesTenantConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'seat_index' => 'integer',
            'is_independent' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'end_reason' => HoldingEndReason::class,
        ];
    }

    /**
     * Cross-database (D-014): resolved on the central connection.
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Cross-database (D-014). Null for an independent, and null is also what a
     * party we have not yet recorded looks like — is_independent is the field
     * that tells those apart.
     *
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
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

    public function isCurrent(?Carbon $on = null): bool
    {
        $on ??= Carbon::today();

        return $this->start_date <= $on
            && ($this->end_date === null || $this->end_date > $on);
    }

    /**
     * Holdings in force on a date. '[)' at both ends: a holding that ends on the
     * day its successor starts is over, which is how a handover is recorded.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrentOn(Builder $query, ?Carbon $on = null): Builder
    {
        $on ??= Carbon::today();

        return $query->whereDate('start_date', '<=', $on)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('end_date')
                ->orWhereDate('end_date', '>', $on));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
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

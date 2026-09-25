<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Offices\Enums\AppointmentType;
use App\Modules\Offices\Enums\ElectionMethod;
use App\Modules\Offices\Enums\GoverningBody;
use App\Modules\Offices\Enums\PositionKey;
use App\Modules\Offices\Enums\SeatCategory;
use App\Modules\Geography\Casts\PostgresTextArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared behaviour of the positions catalogue.
 *
 * The central table is the editable one; each tenant database holds a read-only
 * replica so that office_holdings and v_current_seats can join against it.
 * Both subclasses are otherwise identical, which is why the logic sits here.
 *
 * @property string $key
 * @property string $title_ne
 * @property string $title_en
 * @property array<int, string> $applies_to_local_level_types
 * @property array<string, int> $seats_per_constituency
 */
abstract class BasePosition extends Model
{
    protected $table = 'positions';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'body' => GoverningBody::class,
            'seat_category' => SeatCategory::class,
            'election_method' => ElectionMethod::class,
            'appointment_type' => AppointmentType::class,
            'applies_to_local_level_types' => PostgresTextArray::class,
            'seats_per_constituency' => 'array',
            'ballot_order' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function positionKey(): PositionKey
    {
        return PositionKey::from($this->key);
    }

    /** Seats of this position in a local level of the given type; 0 when it does not apply. */
    public function seatsIn(string $localLevelType): int
    {
        return (int) ($this->seats_per_constituency[$localLevelType] ?? 0);
    }

    public function appliesTo(string $localLevelType): bool
    {
        return in_array($localLevelType, $this->applies_to_local_level_types, true);
    }

    /** Positions in force today. A retired position keeps resolving for past holdings. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('valid_to');
    }

    /** The seats citizens fill themselves — what a ward page shows (docs/02 §4.5). */
    public function scopeDirectlyElected(Builder $query): Builder
    {
        return $query->where('election_method', ElectionMethod::Direct->value);
    }

    public function scopeForLocalLevelType(Builder $query, string $localLevelType): Builder
    {
        return $query->whereRaw('? = ANY (applies_to_local_level_types)', [$localLevelType]);
    }

    /** Ballot order first, then key, so two positions sharing an order stay stable. */
    public function scopeInBallotOrder(Builder $query): Builder
    {
        return $query->orderByRaw('ballot_order NULLS LAST')->orderBy('key');
    }
}

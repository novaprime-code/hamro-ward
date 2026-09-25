<?php

declare(strict_types=1);

namespace App\Modules\Offices\Actions;

use App\Modules\Offices\Enums\HoldingEndReason;
use App\Modules\Offices\Enums\VacancyReason;
use App\Modules\Offices\Exceptions\OfficesException;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Vacancy;
use Illuminate\Support\Carbon;

/**
 * Closes a holding, and optionally records the vacancy it leaves (docs/02 §4.4).
 *
 * Terms end early in Nepal often enough that this is an ordinary operation
 * rather than an exception: officials had to resign to contest the March 2026
 * federal election, and the Election Commission has discussed by-elections for
 * 74 vacant local posts.
 *
 * The vacancy is optional and off by default. Ending a holding is something we
 * observed; declaring the seat empty afterwards is a separate claim needing its
 * own evidence, and it stays not_verified until a source is attached.
 */
final class EndOfficeHolding
{
    public function handle(
        OfficeHolding $holding,
        Carbon $endDate,
        HoldingEndReason $reason,
        bool $recordVacancy = false,
        ?VacancyReason $vacancyReason = null,
    ): OfficeHolding {
        if ($holding->end_date !== null) {
            throw OfficesException::holdingAlreadyEnded($holding->id);
        }

        $holding->forceFill([
            'end_date' => $endDate->toDateString(),
            'end_reason' => $reason,
        ])->save();

        if ($recordVacancy) {
            Vacancy::query()->create([
                'position_key' => $holding->position_key,
                'constituency_id' => $holding->constituency_id,
                'seat_index' => $holding->seat_index,
                'vacant_from' => $endDate->toDateString(),
                'reason' => ($vacancyReason ?? $this->vacancyReasonFor($reason)),
            ]);
        }

        return $holding->refresh();
    }

    private function vacancyReasonFor(HoldingEndReason $reason): VacancyReason
    {
        return match ($reason) {
            HoldingEndReason::Resignation => VacancyReason::Resignation,
            HoldingEndReason::Death => VacancyReason::Death,
            HoldingEndReason::Removal => VacancyReason::Removal,
            HoldingEndReason::Suspension => VacancyReason::Suspension,
            default => VacancyReason::Other,
        };
    }
}

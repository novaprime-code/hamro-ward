<?php

declare(strict_types=1);

namespace App\Modules\Offices\Actions;

use App\Modules\Offices\Enums\VacancyReason;
use App\Modules\Offices\Exceptions\OfficesException;
use App\Modules\Offices\Models\TenantPosition;
use App\Modules\Offices\Models\Vacancy;
use Illuminate\Support\Carbon;

/**
 * Records that a seat is empty (docs/02 §4.2, §4.4).
 *
 * The case this exists for: in 2022 nobody stood for the reserved Dalit woman
 * ward-member seat in 123 local levels. That is a fact about those wards, with
 * a source, and a citizen should be able to read it — not an empty row that
 * looks like the platform forgot.
 *
 * Until a verified source is attached the seat still reads not_verified. A
 * vacancy is a claim, and claims need evidence like any other (FR-SRC-02).
 */
final class RecordVacancy
{
    public function handle(
        string $positionKey,
        string $constituencyId,
        Carbon $vacantFrom,
        int $seatIndex = 1,
        ?VacancyReason $reason = null,
        ?string $note = null,
    ): Vacancy {
        if (! TenantPosition::query()->whereKey($positionKey)->exists()) {
            throw OfficesException::unknownPosition($positionKey);
        }

        return Vacancy::query()->create([
            'position_key' => $positionKey,
            'constituency_id' => $constituencyId,
            'seat_index' => $seatIndex,
            'vacant_from' => $vacantFrom->toDateString(),
            'reason' => $reason,
            'note' => $note,
        ]);
    }
}

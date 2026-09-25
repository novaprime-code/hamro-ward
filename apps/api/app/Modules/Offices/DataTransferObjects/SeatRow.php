<?php

declare(strict_types=1);

namespace App\Modules\Offices\DataTransferObjects;

use App\Modules\Offices\Enums\SeatCategory;
use App\Modules\Offices\Enums\SeatState;
use App\Modules\Offices\Enums\VacancyReason;
use Illuminate\Support\Carbon;

/**
 * One seat of one constituency, as a page renders it.
 *
 * Every seat the catalogue says should exist produces a row, whether or not we
 * know anything about it. That is the whole point: a ward page that silently
 * omits the seats it has no data for tells a citizen their ward has four
 * representatives when it has seven.
 */
final readonly class SeatRow
{
    public function __construct(
        public string $constituencyId,
        public string $constituencyLevel,
        public ?int $wardNumber,
        public string $positionKey,
        public string $titleNe,
        public string $titleEn,
        public SeatCategory $seatCategory,
        public ?int $ballotOrder,
        public int $seatIndex,
        public SeatState $state,
        public ?PersonSummary $person = null,
        public ?PartySummary $party = null,
        public bool $isIndependent = false,
        public ?Carbon $startDate = null,
        public ?string $termLabel = null,
        public ?VacancyReason $vacancyReason = null,
        public ?Carbon $vacantFrom = null,
    ) {}

    public function title(string $locale = 'ne'): string
    {
        return $locale === 'en' ? $this->titleEn : $this->titleNe;
    }

    public function isHeld(): bool
    {
        return $this->state === SeatState::Held;
    }

    /**
     * A holder we cannot yet vouch for. The page shows the seat and says the
     * information is unconfirmed rather than showing a name as fact
     * (FR-SRC-02).
     */
    public function hasUnverifiedHolder(): bool
    {
        return $this->state === SeatState::NotVerified && $this->person !== null;
    }
}

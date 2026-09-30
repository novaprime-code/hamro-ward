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
        /**
         * The record the evidence hangs off, so a page can link to it
         * (HW-E04-F02).
         *
         * Until now the provenance badge said "official source" and linked
         * nowhere, because the row carried the state a source implies but not
         * the identifier of the thing the source is about. A claim a reader
         * cannot follow is the kind this platform tells them not to accept.
         *
         * Null on a seat in the `not_verified` state with nothing recorded at
         * all: there is no record, so there is nothing to show working for.
         */
        public ?string $officeHoldingId = null,
        public ?string $vacancyId = null,
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

    /**
     * Which record to ask for evidence about, as a morph-map key and id.
     *
     * A holding takes precedence over a vacancy: if both exist for one seat the
     * holding is the live fact, and the database's cross-table trigger means
     * they cannot overlap in time anyway.
     *
     * @return array{0: string, 1: string}|null
     */
    public function evidenceSubject(): ?array
    {
        if ($this->officeHoldingId !== null) {
            return ['office_holding', $this->officeHoldingId];
        }

        if ($this->vacancyId !== null) {
            return ['vacancy', $this->vacancyId];
        }

        return null;
    }
}

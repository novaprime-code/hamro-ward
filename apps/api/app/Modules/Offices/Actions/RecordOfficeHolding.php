<?php

declare(strict_types=1);

namespace App\Modules\Offices\Actions;

use App\Modules\Offices\Exceptions\OfficesException;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Models\TenantPosition;
use Illuminate\Support\Carbon;

/**
 * Seats a person (docs/05 §5.4, FR-OFF-02).
 *
 * The database already refuses an overlapping holder, a seat that does not
 * exist and a constituency of the wrong level. This action checks the two
 * things the database cannot: that the person and party actually exist, in the
 * other database (D-014).
 *
 * It does NOT create the source link. A holding with no evidence is allowed to
 * exist as a draft and reads not_verified everywhere until an editor attaches a
 * source and a second editor verifies it (FR-SRC-02, D-002). Making evidence a
 * constructor argument would tempt the importer to invent one.
 */
final class RecordOfficeHolding
{
    /**
     * @param  array<string, mixed>  $attributes  term_label, candidacy_id, end_date, end_reason
     */
    public function handle(
        string $personId,
        string $positionKey,
        string $constituencyId,
        Carbon $startDate,
        int $seatIndex = 1,
        ?string $partyId = null,
        bool $isIndependent = false,
        array $attributes = [],
    ): OfficeHolding {
        if ($isIndependent && $partyId !== null) {
            throw OfficesException::independentWithParty();
        }

        $this->assertPersonIsUsable($personId);

        if ($partyId !== null) {
            $this->assertPartyExists($partyId);
        }

        if (!TenantPosition::query()->whereKey($positionKey)->exists()) {
            throw OfficesException::unknownPosition($positionKey);
        }

        $overlapping = OfficeHolding::query()
            ->forSeat($positionKey, $constituencyId, $seatIndex)
            ->currentOn($startDate)
            ->exists();

        if ($overlapping) {
            throw OfficesException::seatAlreadyHeld($positionKey, $seatIndex);
        }

        return OfficeHolding::query()->create([
            ...$attributes,
            'person_id' => $personId,
            'position_key' => $positionKey,
            'constituency_id' => $constituencyId,
            'seat_index' => $seatIndex,
            'party_id' => $partyId,
            'is_independent' => $isIndependent,
            'start_date' => $startDate->toDateString(),
        ]);
    }

    /**
     * Central lookup. A merged person is refused by name rather than silently
     * accepted, because recording a holding against a row that has been folded
     * into another means the representative's record splits in two.
     */
    private function assertPersonIsUsable(string $personId): void
    {
        $person = Person::query()->find($personId);

        if ($person === null) {
            throw OfficesException::unknownPerson($personId);
        }

        if ($person->isMerged()) {
            throw OfficesException::mergedPerson($personId, (string) $person->merged_into_person_id);
        }
    }

    private function assertPartyExists(string $partyId): void
    {
        if (!Party::query()->whereKey($partyId)->exists()) {
            throw OfficesException::unknownParty($partyId);
        }
    }
}

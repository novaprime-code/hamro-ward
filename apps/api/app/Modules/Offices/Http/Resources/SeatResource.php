<?php

declare(strict_types=1);

namespace App\Modules\Offices\Http\Resources;

use App\Modules\Offices\DataTransferObjects\SeatRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One seat, in the shape the ward page renders (FR-OFF-02, FR-OFF-04).
 *
 * `state` is the field that matters and it is never omitted. A seat with no
 * holder still appears, with state `not_verified`, because a page that quietly
 * drops the seats it knows nothing about tells a citizen their ward has three
 * representatives when it has seven.
 *
 * `person` is present on an unverified seat too. The client shows the name with
 * an explicit "not yet confirmed" marker rather than hiding it: we do know
 * something, and pretending otherwise is its own inaccuracy. What the client
 * must never do is render it as established fact (FR-SRC-02).
 *
 * @property SeatRow $resource
 */
final class SeatResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $seat = $this->resource;

        return [
            'position_key' => $seat->positionKey,
            'title' => ['ne' => $seat->titleNe, 'en' => $seat->titleEn],
            'seat_category' => $seat->seatCategory->value,
            'seat_index' => $seat->seatIndex,
            'ballot_order' => $seat->ballotOrder,
            'constituency_level' => $seat->constituencyLevel,
            'ward_number' => $seat->wardNumber,
            'state' => $seat->state->value,
            'is_independent' => $seat->isIndependent,
            'term_label' => $seat->termLabel,
            'started_on' => $seat->startDate?->toDateString(),
            'person' => $seat->person === null ? null : [
                'slug' => $seat->person->slug,
                'name' => ['ne' => $seat->person->nameNe, 'en' => $seat->person->nameEn],
            ],
            'party' => $seat->party === null ? null : [
                'slug' => $seat->party->slug,
                'name' => ['ne' => $seat->party->nameNe, 'en' => $seat->party->nameEn],
                'abbreviation' => [
                    'ne' => $seat->party->abbreviationNe,
                    'en' => $seat->party->abbreviationEn,
                ],
            ],
            'vacancy' => $seat->vacancyReason === null ? null : [
                'reason' => $seat->vacancyReason->value,
                'since' => $seat->vacantFrom?->toDateString(),
            ],
            /*
             * Where to read the working. The badge on a seat row used to say
             * "official source" and go nowhere; this is the address of the
             * record the sources are attached to, so the client can link to it
             * (HW-E04-F02).
             *
             * Null means no record exists for this seat, which is a different
             * thing from a record with no sources — the first has nothing to
             * show, the second has an empty evidence page that says so.
             */
            'evidence' => $this->evidence($seat),
        ];
    }

    /**
     * @return array{subject_type: string, subject_id: string}|null
     */
    private function evidence(SeatRow $seat): ?array
    {
        $subject = $seat->evidenceSubject();

        if ($subject === null) {
            return null;
        }

        [$type, $id] = $subject;

        return ['subject_type' => $type, 'subject_id' => $id];
    }
}

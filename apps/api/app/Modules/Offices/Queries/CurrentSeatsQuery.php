<?php

declare(strict_types=1);

namespace App\Modules\Offices\Queries;

use App\Modules\Offices\DataTransferObjects\PartySummary;
use App\Modules\Offices\DataTransferObjects\PersonSummary;
use App\Modules\Offices\DataTransferObjects\SeatRow;
use App\Modules\Offices\Enums\SeatCategory;
use App\Modules\Offices\Enums\SeatState;
use App\Modules\Offices\Enums\VacancyReason;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use stdClass;

/**
 * "Who represents ward 4?" — answered in three queries, whatever the ward size
 * (docs/05 §5.5, FR-OFF-02).
 *
 * The view does the structural work inside the tenant: it generates the seats
 * the catalogue says exist and attaches the current holding or vacancy and its
 * verification state. This class then hydrates names, which live in the central
 * database and so cannot be joined (D-014) — one batched lookup for people, one
 * for parties, never one per seat.
 *
 * Three queries, not N+1, and never a cross-database join that PostgreSQL would
 * refuse anyway.
 */
final class CurrentSeatsQuery
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Every seat of one constituency, in ballot order (docs/02 §4.1).
     *
     * @return Collection<int, SeatRow>
     */
    public function forConstituency(string $constituencyId): Collection
    {
        return $this->hydrate(
            $this->view()
                ->where('constituency_id', $constituencyId)
                ->orderByRaw('ballot_order NULLS LAST')
                ->orderBy('position_key')
                ->orderBy('seat_index')
                ->get(),
        );
    }

    /**
     * A ward page shows two constituencies at once: the seats of the ward
     * itself, and the local-level-wide seats every ward shares (docs/02 §4.1).
     * Returning them together, labelled by constituency_level, is what lets the
     * page say which is which instead of implying the mayor is a ward official.
     *
     * @return Collection<int, SeatRow>
     */
    public function forWardPage(string $wardId, string $localLevelId): Collection
    {
        return $this->hydrate(
            $this->view()
                ->whereIn('constituency_id', [$wardId, $localLevelId])
                ->orderByRaw("constituency_level = 'ward' DESC")
                ->orderByRaw('ballot_order NULLS LAST')
                ->orderBy('position_key')
                ->orderBy('seat_index')
                ->get(),
        );
    }

    /**
     * Every seat in the local level, for the "who governs this municipality"
     * page and for the nightly completeness report.
     *
     * @return Collection<int, SeatRow>
     */
    public function forLocalLevel(string $localLevelId): Collection
    {
        return $this->hydrate(
            $this->view()
                ->where('local_level_id', $localLevelId)
                ->orderByRaw("constituency_level = 'local_level' DESC")
                ->orderBy('ward_number')
                ->orderByRaw('ballot_order NULLS LAST')
                ->orderBy('position_key')
                ->orderBy('seat_index')
                ->get(),
        );
    }

    /**
     * The seats one person currently holds in this municipality (HW-E05-F02).
     *
     * Scoped to the resolved tenant, and current only. Two consequences worth
     * being explicit about, because both are visible to a reader:
     *
     *  - A career spanning several municipalities shows only this one's part of
     *    it. Holdings live in per-municipality databases and PostgreSQL will
     *    not join across them, so the whole story needs a central index that
     *    does not exist yet.
     *  - A finished term does not appear. `v_current_seats` answers "who
     *    represents this ward today", which is the question the rest of the
     *    site asks; a term history is its own feature with its own view.
     *
     * Neither is a limitation to paper over on the page. A person page that
     * silently showed only part of a career while looking complete would be
     * making exactly the kind of unstated claim this platform exists to avoid.
     *
     * @return Collection<int, SeatRow>
     */
    public function forPerson(string $personId): Collection
    {
        return $this->hydrate(
            $this->view()
                ->where('person_id', $personId)
                ->orderByRaw("constituency_level = 'local_level' DESC")
                ->orderBy('ward_number')
                ->orderByRaw('ballot_order NULLS LAST')
                ->orderBy('position_key')
                ->orderBy('seat_index')
                ->get(),
        );
    }

    /**
     * How much of a constituency's information is confirmed — the number a ward
     * page states plainly rather than hiding empty seats (FR-OFF-04).
     *
     * @return array{total: int, held: int, vacant: int, not_verified: int}
     */
    public function coverageFor(string $constituencyId): array
    {
        $rows = $this->view()
            ->selectRaw('seat_state, count(*) as seats')
            ->where('constituency_id', $constituencyId)
            ->groupBy('seat_state')
            ->pluck('seats', 'seat_state');

        $held = (int) ($rows[SeatState::Held->value] ?? 0);
        $vacant = (int) ($rows[SeatState::Vacant->value] ?? 0);
        $notVerified = (int) ($rows[SeatState::NotVerified->value] ?? 0);

        return [
            'total' => $held + $vacant + $notVerified,
            'held' => $held,
            'vacant' => $vacant,
            'not_verified' => $notVerified,
        ];
    }

    private function view(): Builder
    {
        return $this->db
            ->connection((string) config('tenancy.tenant_connection'))
            ->table('v_current_seats');
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<int, SeatRow>
     */
    private function hydrate(Collection $rows): Collection
    {
        $people = $this->peopleFor($rows->pluck('person_id')->filter()->unique()->values()->all());
        $parties = $this->partiesFor($rows->pluck('party_id')->filter()->unique()->values()->all());
        $conflicts = $this->conflictedSubjects($rows);

        return $rows->map(fn (stdClass $row): SeatRow => new SeatRow(
            constituencyId: (string) $row->constituency_id,
            constituencyLevel: (string) $row->constituency_level,
            wardNumber: $row->ward_number === null ? null : (int) $row->ward_number,
            positionKey: (string) $row->position_key,
            titleNe: (string) $row->title_ne,
            titleEn: (string) $row->title_en,
            seatCategory: SeatCategory::from((string) $row->seat_category),
            ballotOrder: $row->ballot_order === null ? null : (int) $row->ballot_order,
            seatIndex: (int) $row->seat_index,
            state: SeatState::from((string) $row->seat_state),
            person: $row->person_id === null ? null : ($people[$row->person_id] ?? null),
            party: $row->party_id === null ? null : ($parties[$row->party_id] ?? null),
            isIndependent: (bool) ($row->is_independent ?? false),
            startDate: $row->start_date === null ? null : Carbon::parse((string) $row->start_date),
            termLabel: $row->term_label === null ? null : (string) $row->term_label,
            vacancyReason: $row->vacancy_reason === null
                ? null
                : VacancyReason::from((string) $row->vacancy_reason),
            vacantFrom: $row->vacant_from === null ? null : Carbon::parse((string) $row->vacant_from),
            officeHoldingId: $row->office_holding_id === null ? null : (string) $row->office_holding_id,
            vacancyId: $row->vacancy_id === null ? null : (string) $row->vacancy_id,
            hasSourceConflict: isset($conflicts[(string) ($row->office_holding_id ?? $row->vacancy_id ?? '')]),
        ));
    }

    /**
     * Which of these seats have a field their sources disagree about.
     *
     * One grouped query for the whole page, not one per seat. A disagreement is
     * defined the same way the evidence page defines it — two or more DISTINCT
     * asserted values for one field — because a seat row that flags a conflict
     * and an evidence page that shows none would be worse than neither.
     *
     * Corroboration is not conflict: two sources saying the same thing must not
     * raise a warning, or the marker stops meaning anything.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, true> keyed by holding or vacancy id
     */
    private function conflictedSubjects(Collection $rows): array
    {
        $ids = $rows
            ->flatMap(fn (stdClass $row): array => [$row->office_holding_id, $row->vacancy_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return $this->db
            ->connection((string) config('tenancy.tenant_connection'))
            ->table('source_links')
            ->whereIn('subject_type', ['office_holding', 'vacancy'])
            ->whereIn('subject_id', $ids)
            ->whereNotNull('field_path')
            ->whereNotNull('asserted_value')
            ->groupBy('subject_id', 'field_path')
            ->havingRaw('count(DISTINCT asserted_value) > 1')
            ->pluck('subject_id')
            ->flip()
            ->map(fn (): bool => true)
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, PersonSummary>
     */
    private function peopleFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Person::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (Person $person): array => [
                $person->id => PersonSummary::fromModel($person),
            ])
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, PartySummary>
     */
    private function partiesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Party::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (Party $party): array => [
                $party->id => PartySummary::fromModel($party),
            ])
            ->all();
    }
}
<?php

declare(strict_types=1);

namespace App\Modules\Imports\Actions;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Imports\Support\Cells;
use App\Modules\Imports\Support\ImportContext;
use App\Modules\Imports\Support\ImportFile;
use App\Modules\Imports\Support\ImportReport;
use App\Modules\Imports\Support\ImportRow;
use App\Modules\Imports\Support\RowRejected;
use App\Modules\Imports\Support\SubjectRef;
use App\Modules\Offices\Enums\HoldingEndReason;
use App\Modules\Offices\Enums\VacancyReason;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Models\TenantPosition;
use App\Modules\Offices\Models\Vacancy;
use App\Modules\Offices\Models\WardOffice;
use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Models\Source;
use App\Modules\Provenance\Models\TenantSource;
use App\Modules\Provenance\Models\TenantSourceLink;
use App\Modules\Tenancy\Actions\RecordOutboxEvent;
use Illuminate\Support\Facades\DB;

/**
 * The municipality half of an import (docs/05 §13): local sources, office
 * holdings, vacancies, ward offices, and verifications of those. Runs inside
 * the caller's tenant transaction, with the tenant already initialized.
 *
 * Holdings point at central persons and parties across the database boundary
 * (D-014), so each reference is checked here: PostgreSQL cannot do it. The
 * seat rules — one holder at a time, a seat that exists, a ward chair in a
 * ward — are the database's, and a row that breaks one is reported with the
 * database's reason in plain words.
 */
final class ImportTenantRows
{
    /**
     * What `field_sources` may cite, and the column each name stands for.
     * Sheet names, not column names: the sheet says party_ref because that is
     * the column the person filling it in can see.
     */
    public const HOLDING_FIELDS = [
        'party_ref' => 'party_id',
        'is_independent' => 'is_independent',
        'start_date' => 'start_date',
        'end_date' => 'end_date',
        'end_reason' => 'end_reason',
        'term_label' => 'term_label',
    ];

    private ImportContext $context;

    public function __construct(private readonly RecordOutboxEvent $outbox) {}

    /** @param  array<string, list<ImportRow>>  $rows  by file name */
    public function handle(ImportContext $context, array $rows): void
    {
        $this->context = $context;
        $tenant = DB::connection((string) config('tenancy.tenant_connection'));

        if (TenantPosition::query()->doesntExist()) {
            foreach ([ImportFile::OfficeHoldings, ImportFile::Vacancies] as $file) {
                if (($rows[$file->value] ?? []) !== []) {
                    $context->report->error($file, 1, null, 'this municipality has no positions catalogue. Run db:seed, then hw:tenant:sync-reference.');

                    return;
                }
            }
        }

        $context->each($this->localSourceRows($rows[ImportFile::Sources->value] ?? []), $tenant, $this->source(...));
        $context->each($rows[ImportFile::OfficeHoldings->value] ?? [], $tenant, $this->holding(...));
        $context->each($rows[ImportFile::Vacancies->value] ?? [], $tenant, $this->vacancy(...));
        $context->each($rows[ImportFile::WardOffices->value] ?? [], $tenant, $this->wardOffice(...));
        $context->each($this->tenantVerifications($rows[ImportFile::Verifications->value] ?? []), $tenant, $this->verification(...));
    }

    // -- sources ----------------------------------------------------------------

    /**
     * @param  list<ImportRow>  $rows
     * @return list<ImportRow>
     */
    private function localSourceRows(array $rows): array
    {
        return array_values(array_filter($rows, fn (ImportRow $row): bool => in_array(
            SourceTypeKey::tryFrom((string) $row->get('source_type_key')),
            ImportCentralRows::LOCAL_SOURCE_TYPES,
            true,
        )));
    }

    private function source(ImportRow $row): void
    {
        $attributes = ImportCentralRows::sourceAttributes($row);
        $id = ImportContext::id('source', (string) $row->get('source_ref'));

        if (Source::query()->whereKey($id)->exists()) {
            throw RowRejected::in('source_type_key', 'this source_ref was loaded earlier as a national document; a source cannot move between national and local.');
        }

        $source = TenantSource::query()->find($id) ?? new TenantSource;

        $this->context->persist($source, ['id' => $id, ...$attributes], 'source', tenantSide: true);
    }

    // -- seats ------------------------------------------------------------------

    private function holding(ImportRow $row): void
    {
        $personId = $this->person($row, 'person_ref');
        $positionKey = $this->position($row);
        $constituency = $this->constituency($row, 'constituency_path');
        $seatIndex = Cells::int($row, 'seat_index', default: 1, min: 1, max: 99);
        $startDate = Cells::date($row, 'start_date', required: true);

        $partyRef = Cells::ref($row, 'party_ref', required: false);
        $party = $partyRef === null ? null : $this->party($partyRef);
        $isIndependent = Cells::bool($row, 'is_independent');

        if ($isIndependent && $party !== null) {
            throw RowRejected::in('party_ref', 'an independent has no party; leave party_ref empty or is_independent false.');
        }

        $endDate = Cells::date($row, 'end_date');
        $endReason = Cells::enum($row, 'end_reason', HoldingEndReason::class);

        if (($endDate === null) !== ($endReason === null)) {
            throw RowRejected::in($endDate === null ? 'end_date' : 'end_reason', 'end_date and end_reason go together: a term that ended has a reason, and a reason needs a date.');
        }

        if ($endDate !== null && ! $endDate->isAfter($startDate)) {
            throw RowRejected::in('end_date', 'must be after start_date.');
        }

        $termLabel = $row->get('term_label');

        if ($termLabel !== null && mb_strlen($termLabel) > 40) {
            throw RowRejected::in('term_label', 'is longer than 40 characters.');
        }

        $fieldSources = $this->fieldSources($row);

        $holding = OfficeHolding::query()
            ->where('person_id', $personId)
            ->where('position_key', $positionKey)
            ->where('constituency_id', $constituency->id)
            ->where('seat_index', $seatIndex)
            ->where('start_date', $startDate->toDateString())
            ->first() ?? new OfficeHolding;

        $outcome = $this->context->persist($holding, [
            'person_id' => $personId,
            'position_key' => $positionKey,
            'constituency_id' => $constituency->id,
            'seat_index' => $seatIndex,
            'start_date' => $startDate,
            'party_id' => $party?->id,
            'is_independent' => $isIndependent,
            'end_date' => $endDate,
            'end_reason' => $endReason?->value,
            'term_label' => $termLabel,
        ], 'office_holding', tenantSide: true);

        $holdingId = (string) $holding->id;

        // In the same tenant transaction as the holding, so the central index
        // learns of exactly the changes that commit (docs/12 §4.3).
        if ($outcome !== ImportReport::OUTCOME_UNCHANGED) {
            $this->outbox->holdingChanged($holdingId, $personId);
        }

        if (($sourceRef = $row->get('source_ref')) !== null) {
            $this->context->link(true, 'office_holding', $holdingId, null, $sourceRef, 'source_ref');
        }

        $asserted = [
            'party_ref' => $party === null ? null : ($party->name_en ?? $party->name_ne),
            'is_independent' => $isIndependent,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate?->toDateString(),
            'end_reason' => $endReason?->value,
            'term_label' => $termLabel,
        ];

        foreach ($fieldSources as [$field, $sourceRef]) {
            if ($asserted[$field] === null) {
                throw RowRejected::in('field_sources', "cites a source for {$field}, but the row has no {$field}. A source cannot back a value that is not there.");
            }

            $this->context->link(true, 'office_holding', $holdingId, self::HOLDING_FIELDS[$field], $sourceRef, 'field_sources', $asserted[$field]);
        }
    }

    /**
     * `party_ref:src12;end_date:src19` — which source backs which field
     * (docs/05 §4.4: party, end date and contact fields do not inherit the
     * record's source).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function fieldSources(ImportRow $row): array
    {
        $value = $row->get('field_sources');

        if ($value === null) {
            return [];
        }

        $pairs = [];

        foreach (array_filter(array_map('trim', explode(';', $value))) as $pair) {
            [$field, $ref] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');

            if (! isset(self::HOLDING_FIELDS[$field])) {
                throw RowRejected::in('field_sources', "\"{$field}\" is not a field that takes its own source; use one of: ".implode(', ', array_keys(self::HOLDING_FIELDS)).'.');
            }

            if ($ref === '') {
                throw RowRejected::in('field_sources', "\"{$pair}\" names no source; write it as {$field}:source_ref.");
            }

            $pairs[] = [$field, $ref];
        }

        return $pairs;
    }

    private function vacancy(ImportRow $row): void
    {
        $positionKey = $this->position($row);
        $constituency = $this->constituency($row, 'constituency_path');
        $seatIndex = Cells::int($row, 'seat_index', default: 1, min: 1, max: 99);
        $vacantFrom = Cells::date($row, 'vacant_from', required: true);
        $vacantTo = Cells::date($row, 'vacant_to');

        if ($vacantTo !== null && ! $vacantTo->isAfter($vacantFrom)) {
            throw RowRejected::in('vacant_to', 'must be after vacant_from.');
        }

        $vacancy = Vacancy::query()
            ->where('position_key', $positionKey)
            ->where('constituency_id', $constituency->id)
            ->where('seat_index', $seatIndex)
            ->where('vacant_from', $vacantFrom->toDateString())
            ->first() ?? new Vacancy;

        $this->context->persist($vacancy, [
            'position_key' => $positionKey,
            'constituency_id' => $constituency->id,
            'seat_index' => $seatIndex,
            'vacant_from' => $vacantFrom,
            'vacant_to' => $vacantTo,
            'reason' => Cells::enum($row, 'reason', VacancyReason::class)?->value,
        ], 'vacancy', tenantSide: true);

        if (($sourceRef = $row->get('source_ref')) !== null) {
            $this->context->link(true, 'vacancy', (string) $vacancy->id, null, $sourceRef, 'source_ref');
        }
    }

    private function wardOffice(ImportRow $row): void
    {
        $ward = $this->constituency($row, 'ward_path');

        if ($ward->level !== AdminLevel::Ward) {
            throw RowRejected::in('ward_path', 'must be a ward, e.g. koshi/sunsari/example/4.');
        }

        foreach (['address_ne' => 300, 'address_en' => 300] as $column => $max) {
            if (mb_strlen((string) $row->get($column)) > $max) {
                throw RowRejected::in($column, "is longer than {$max} characters.");
            }
        }

        $phone = $row->get('phone');

        if ($phone !== null && (preg_match('/^[0-9+][0-9 +()-]{4,}$/', $phone) !== 1 || mb_strlen($phone) > 60)) {
            throw RowRejected::in('phone', "\"{$phone}\" is not a phone number: digits, spaces, + ( ) and - only.");
        }

        $coordinates = Cells::coordinates($row);

        /*
         * location is read back as EWKT so that an unchanged pin compares
         * equal and a re-run stays a no-op. Read raw, PostGIS returns hex
         * EWKB, which never matches the text written and would make every
         * ward office "updated" on every run.
         */
        $office = WardOffice::query()
            ->select('*')
            ->selectRaw('ST_AsEWKT(location) AS location')
            ->where('ward_id', $ward->id)
            ->first() ?? new WardOffice;

        $this->context->persist($office, [
            'ward_id' => $ward->id,
            'address_ne' => $row->get('address_ne'),
            'address_en' => $row->get('address_en'),
            'phone' => $phone,
            'email' => Cells::email($row, 'email'),
            'location' => $coordinates === null ? null : sprintf('SRID=4326;POINT(%s %s)', $coordinates[1], $coordinates[0]),
        ], 'ward_office', tenantSide: true);

        if (($sourceRef = $row->get('source_ref')) !== null) {
            $this->context->link(true, 'ward_office', (string) $office->id, null, $sourceRef, 'source_ref');
        }
    }

    // -- verifications ----------------------------------------------------------

    /**
     * @param  list<ImportRow>  $rows
     * @return list<ImportRow>
     */
    private function tenantVerifications(array $rows): array
    {
        return array_values(array_filter($rows, fn (ImportRow $row): bool => in_array(
            strstr((string) $row->get('subject_ref'), ':', before_needle: true),
            ['ward_office', 'office_holding', 'vacancy'],
            true,
        )));
    }

    private function verification(ImportRow $row): void
    {
        $subject = SubjectRef::parse($row);
        [$verifiedBy, $reviewedBy, $verifiedOn] = Cells::verifiers($row);
        $sourceRef = (string) Cells::ref($row, 'source_ref');
        $source = $this->context->source($sourceRef) ?? throw RowRejected::in('source_ref', "no source \"{$sourceRef}\".");

        $field = $row->get('field');

        if ($field !== null && ($subject->type !== 'office_holding' || ! isset(self::HOLDING_FIELDS[$field]))) {
            throw RowRejected::in('field', $subject->type === 'office_holding'
                ? "\"{$field}\" is not one of: ".implode(', ', array_keys(self::HOLDING_FIELDS)).'.'
                : "only office holdings carry per-field sources; leave it empty for a {$subject->type}.");
        }

        $subjectId = $this->subjectId($subject);

        $link = TenantSourceLink::query()
            ->where('source_scope', $source['scope']->value)
            ->where('source_id', $source['id'])
            ->where('subject_type', $subject->type)
            ->where('subject_id', $subjectId)
            ->when(
                $field === null,
                fn ($q) => $q->whereNull('field_path'),
                fn ($q) => $q->where('field_path', self::HOLDING_FIELDS[$field]),
            )
            ->first()
            ?? throw RowRejected::in('subject_ref', sprintf(
                '%s does not cite "%s"%s. Cite it in that row first; a verification confirms a citation, it does not create one.',
                $subject->display(),
                $sourceRef,
                $field === null ? '' : " for {$field}",
            ));

        $outcome = $this->context->verify($link, $verifiedBy, $reviewedBy, $verifiedOn, tenantSide: true);

        $this->context->report->verification($subject->display(), $field ?? '—', $sourceRef, $verifiedBy, $reviewedBy, $verifiedOn->toDateString(), $outcome);
    }

    private function subjectId(SubjectRef $subject): string
    {
        $missing = RowRejected::in('subject_ref', "no {$subject->type} matches {$subject->display()}.");

        return match ($subject->type) {
            'ward_office' => (string) (WardOffice::query()->where('ward_id', $this->unitAt($subject->parts[0])?->id)->value('id') ?? throw $missing),
            'office_holding' => (string) (OfficeHolding::query()
                ->where('person_id', ImportContext::id('person', $subject->parts[0]))
                ->where('position_key', $subject->parts[1])
                ->where('constituency_id', $this->unitAt($subject->parts[2])?->id)
                ->where('seat_index', (int) $subject->parts[3])
                ->where('start_date', $subject->parts[4])
                ->value('id') ?? throw $missing),
            'vacancy' => (string) (Vacancy::query()
                ->where('position_key', $subject->parts[0])
                ->where('constituency_id', $this->unitAt($subject->parts[1])?->id)
                ->where('seat_index', (int) $subject->parts[2])
                ->where('vacant_from', $subject->parts[3])
                ->value('id') ?? throw $missing),
            default => throw $missing,
        };
    }

    // -- references -------------------------------------------------------------

    /** A central person, checked across the database boundary (docs/12 §4.1). */
    private function person(ImportRow $row, string $column): string
    {
        $ref = (string) Cells::ref($row, $column);
        $person = Person::query()->find(ImportContext::id('person', $ref))
            ?? throw RowRejected::in($column, "no person \"{$ref}\" in persons.csv or in the database.");

        if ($person->isMerged()) {
            throw RowRejected::in($column, "\"{$ref}\" was merged into another person record. Use the ref of the record that was kept.");
        }

        return (string) $person->id;
    }

    private function party(string $ref): Party
    {
        return Party::query()->find(ImportContext::id('party', $ref))
            ?? throw RowRejected::in('party_ref', "no party \"{$ref}\" in parties.csv or in the database.");
    }

    private function position(ImportRow $row): string
    {
        $key = Cells::required($row, 'position_key');

        if (TenantPosition::query()->whereKey($key)->doesntExist()) {
            throw RowRejected::in('position_key', "\"{$key}\" is not a position in the catalogue (e.g. ward_chair, ward_member_open, mayor).");
        }

        return $key;
    }

    /** A ward or the local level itself, inside THIS municipality. */
    private function constituency(ImportRow $row, string $column): TenantAdminUnit
    {
        $path = (string) Cells::path($row, $column);

        return $this->unitAt($path) ?? throw RowRejected::in($column, "\"{$path}\" is not a current ward or local level of this municipality. "
            .'If the ward was added recently, run hw:tenant:sync-reference first.');
    }

    private function unitAt(string $path): ?TenantAdminUnit
    {
        return TenantAdminUnit::query()
            ->where('slug_path', trim($path, '/'))
            ->whereIn('level', [AdminLevel::LocalLevel->value, AdminLevel::Ward->value])
            ->whereNull('valid_to')
            ->first();
    }
}

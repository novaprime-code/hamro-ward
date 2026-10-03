<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

/**
 * The files `hw:import` understands, and their exact header rows
 * (docs/05 §13).
 *
 * A header is matched exactly — same names, same order. A spreadsheet export
 * that renamed one column, or a hand edit that swapped two, would otherwise
 * load one field's values into another and report success. Refusing the file
 * costs the person preparing it a minute; loading phone numbers into the
 * email column costs a citizen a wrong number on their ward page.
 *
 * Cases are declared in processing order. Sources come first because every
 * other file cites them; verifications last because they verify links the
 * other files create.
 */
enum ImportFile: string
{
    case Sources = 'sources.csv';
    case AdminUnits = 'admin_units.csv';
    case Aliases = 'aliases.csv';
    case Parties = 'parties.csv';
    case Persons = 'persons.csv';
    case OfficeHoldings = 'office_holdings.csv';
    case Vacancies = 'vacancies.csv';
    case WardOffices = 'ward_offices.csv';
    case Verifications = 'verifications.csv';

    /** @return list<string> */
    public function columns(): array
    {
        return match ($this) {
            self::AdminUnits => ['level', 'parent_path', 'slug', 'name_ne', 'name_en', 'local_level_type', 'ward_number', 'valid_from', 'cbs_code', 'source_ref'],
            self::Aliases => ['unit_path', 'alias', 'script', 'kind'],
            self::Sources => ['source_ref', 'source_type_key', 'title', 'publisher', 'url', 'published_at', 'published_as_written', 'retrieved_at', 'language', 'notes'],
            self::Persons => ['person_ref', 'full_name_ne', 'full_name_en', 'source_ref'],
            self::Parties => ['party_ref', 'name_ne', 'name_en', 'abbreviation_ne', 'abbreviation_en', 'source_ref'],
            self::OfficeHoldings => ['person_ref', 'position_key', 'constituency_path', 'seat_index', 'start_date', 'party_ref', 'is_independent', 'end_date', 'end_reason', 'term_label', 'source_ref', 'field_sources'],
            self::Vacancies => ['position_key', 'constituency_path', 'seat_index', 'vacant_from', 'vacant_to', 'reason', 'source_ref'],
            self::WardOffices => ['ward_path', 'address_ne', 'address_en', 'phone', 'email', 'lat', 'lng', 'source_ref'],
            self::Verifications => ['source_ref', 'subject_ref', 'field', 'verified_by_name', 'reviewed_by_name', 'verified_on'],
        };
    }

    /**
     * The natural key a row is upserted on. Two rows in one file with the same
     * key are refused: one of them would silently win, and which one depends
     * on row order nobody is checking.
     *
     * @return list<string>
     */
    public function keyColumns(): array
    {
        return match ($this) {
            self::AdminUnits => ['level', 'parent_path', 'slug'],
            self::Aliases => ['unit_path', 'alias'],
            self::Sources => ['source_ref'],
            self::Persons => ['person_ref'],
            self::Parties => ['party_ref'],
            self::OfficeHoldings => ['person_ref', 'position_key', 'constituency_path', 'seat_index', 'start_date'],
            self::Vacancies => ['position_key', 'constituency_path', 'seat_index', 'vacant_from'],
            self::WardOffices => ['ward_path'],
            self::Verifications => ['source_ref', 'subject_ref', 'field'],
        };
    }

    /** Files whose rows are written to a tenant database. */
    public function isTenantOnly(): bool
    {
        return in_array($this, [self::OfficeHoldings, self::Vacancies, self::WardOffices], true);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

/**
 * The `subject_ref` column of verifications.csv: which record a verification
 * is about, written in the sheet's own terms rather than as a database id the
 * person filling it in has never seen.
 *
 *   person:{person_ref}
 *   party:{party_ref}
 *   admin_unit:{slug path}
 *   ward_office:{ward path}
 *   office_holding:{person_ref}|{position_key}|{constituency_path}|{seat_index}|{start_date}
 *   vacancy:{position_key}|{constituency_path}|{seat_index}|{vacant_from}
 *
 * The office_holding and vacancy forms are their natural keys from
 * docs/05 §13, in the same order as the columns of their own files.
 */
final readonly class SubjectRef
{
    private const PARTS = [
        'person' => 1,
        'party' => 1,
        'admin_unit' => 1,
        'ward_office' => 1,
        'office_holding' => 5,
        'vacancy' => 4,
    ];

    private const TENANT_TYPES = ['ward_office', 'office_holding', 'vacancy'];

    /** @param  list<string>  $parts */
    private function __construct(public string $type, public array $parts) {}

    public static function parse(ImportRow $row): self
    {
        $value = Cells::required($row, 'subject_ref');

        if (! str_contains($value, ':')) {
            throw RowRejected::in('subject_ref', "\"{$value}\" should start with the kind of record, e.g. person:p12 or office_holding:p12|ward_chair|koshi/sunsari/example/4|1|2022-05-30.");
        }

        [$type, $rest] = explode(':', $value, 2);
        $parts = array_map('trim', explode('|', $rest));

        if (! isset(self::PARTS[$type])) {
            throw RowRejected::in('subject_ref', "\"{$type}\" is not a record kind that can be verified here; use one of: ".implode(', ', array_keys(self::PARTS)).'.');
        }

        if (count($parts) !== self::PARTS[$type] || in_array('', $parts, true)) {
            throw RowRejected::in('subject_ref', "\"{$value}\" needs ".self::PARTS[$type].' part(s) separated by "|" after "'.$type.':".');
        }

        return new self($type, $parts);
    }

    public function isTenantSubject(): bool
    {
        return in_array($this->type, self::TENANT_TYPES, true);
    }

    public function display(): string
    {
        return $this->type.':'.implode('|', $this->parts);
    }
}

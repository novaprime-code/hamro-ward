<?php

declare(strict_types=1);

namespace App\Modules\Provenance\DataTransferObjects;

/**
 * Everything a reader can be shown about why one record says what it says.
 *
 * Split into two because they answer different questions. `record` is evidence
 * for the row as a whole — "this holding happened" — and `fields` is evidence
 * for individual values, which is where disagreement lives: two sources can
 * agree that someone holds a seat and disagree about which party they were
 * elected for, and that is exactly the Koshara ward 1 case in the demonstration
 * dataset.
 *
 * An empty Evidence is a legitimate answer, not an error. It means the record
 * exists and nothing backs it yet, which is precisely the `not_verified` state
 * the seat list already reports — and the page should say so plainly rather
 * than 404.
 */
final readonly class Evidence
{
    /**
     * @param  list<EvidenceItem>  $record
     * @param  list<EvidenceField>  $fields
     */
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public array $record,
        public array $fields,
    ) {}

    public function isEmpty(): bool
    {
        return $this->record === [] && $this->fields === [];
    }

    public function hasConflict(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->inConflict) {
                return true;
            }
        }

        return false;
    }
}

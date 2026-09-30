<?php

declare(strict_types=1);

namespace App\Modules\Provenance\DataTransferObjects;

/**
 * The sources for one field of one record, and whether they agree.
 *
 * `inConflict` is the field this whole module exists to be able to set. When
 * two sources say different things, the platform's rule is not to choose
 * quietly: keep both, show the disagreement, and say which source carries more
 * authority (§4). A page cannot do that unless the query tells it.
 */
final readonly class EvidenceField
{
    /**
     * @param  list<EvidenceItem>  $items  highest authority first
     */
    public function __construct(
        public string $fieldPath,
        public bool $inConflict,
        public array $items,
    ) {}
}

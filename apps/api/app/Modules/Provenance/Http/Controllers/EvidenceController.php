<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Http\Controllers;

use App\Modules\Provenance\DataTransferObjects\EvidenceField;
use App\Modules\Provenance\DataTransferObjects\EvidenceItem;
use App\Modules\Provenance\Queries\EvidenceForSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Show the working (HW-E04-F02, FR-SRC-03).
 *
 * The one endpoint that makes the rest of the site checkable. Everywhere else
 * the platform asserts something — this person holds this seat, this seat is
 * vacant, this is the ward office's number — a badge points here, and here is
 * where a reader finds out which document said so, who published it, when we
 * fetched it, and whether anything contradicts it.
 *
 * An empty result is a 200, not a 404. "This record exists and nothing backs it
 * yet" is a true and useful answer, and it is the same answer the seat list
 * already gives as `not_verified`; turning it into an error would make the
 * honest state look like a broken page.
 */
final class EvidenceController
{
    /**
     * GET /api/v1/evidence/{province}/{district}/{local_level}/{subject_type}/{subject_id}
     */
    public function show(Request $request, EvidenceForSubject $evidence): JsonResponse
    {
        $result = $evidence->handle(
            (string) $request->route('subject_type'),
            (string) $request->route('subject_id'),
        );

        return response()->json([
            'data' => [
                'subject_type' => $result->subjectType,
                'subject_id' => $result->subjectId,
                'is_empty' => $result->isEmpty(),
                'has_conflict' => $result->hasConflict(),
                'record' => array_map($this->item(...), $result->record),
                'fields' => array_map($this->field(...), $result->fields),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function field(EvidenceField $field): array
    {
        return [
            'field_path' => $field->fieldPath,
            'in_conflict' => $field->inConflict,
            'sources' => array_map($this->item(...), $field->items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(EvidenceItem $item): array
    {
        return [
            'source_id' => $item->sourceId,
            'title' => $item->title,
            'publisher' => $item->publisher,
            // The link out. Null when the source is a document we hold rather
            // than a page on the internet; media serving arrives in HW-E12.
            'url' => $item->url,
            'source_type' => [
                'key' => $item->sourceTypeKey,
                'label' => ['ne' => $item->sourceTypeLabelNe, 'en' => $item->sourceTypeLabelEn],
                'authority_rank' => $item->authorityRank,
            ],
            'provenance_type' => $item->provenanceType,
            'verification_status' => $item->verificationStatus,
            'asserted_value' => $item->assertedValue,
            'locator' => $item->locator,
            'excerpt' => $item->excerpt,
            'published_at' => $item->publishedAt,
            'retrieved_at' => $item->retrievedAt,
        ];
    }
}

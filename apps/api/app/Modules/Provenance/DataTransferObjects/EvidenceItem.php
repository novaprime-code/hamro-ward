<?php

declare(strict_types=1);

namespace App\Modules\Provenance\DataTransferObjects;

/**
 * One source, and what it says about one fact.
 *
 * Flat and readable on purpose: this travels straight to a page whose entire
 * job is to let a sceptical reader check our working, so every field here is
 * one a reader might reasonably want — who published it, when, where in the
 * document, and the words themselves.
 *
 * `assertedValue` is what THIS source claims, which is not necessarily what the
 * site shows. Keeping them separate is what makes a disagreement visible
 * instead of resolved in silence (§4).
 */
final readonly class EvidenceItem
{
    public function __construct(
        public string $sourceId,
        /** Null means the source backs the whole record rather than one field. */
        public ?string $fieldPath,
        public string $provenanceType,
        public string $verificationStatus,
        public ?string $assertedValue,
        /** Page, section or table row inside the document. */
        public ?string $locator,
        public ?string $excerpt,
        public string $title,
        public ?string $publisher,
        public ?string $url,
        public string $sourceTypeKey,
        /** 1 is the Election Commission; higher is less authoritative (§4). */
        public int $authorityRank,
        public string $sourceTypeLabelNe,
        public string $sourceTypeLabelEn,
        public ?string $publishedAt,
        public ?string $retrievedAt,
    ) {}

    public function isVerified(): bool
    {
        return $this->verificationStatus === 'verified';
    }

    public function sourceTypeLabel(string $locale = 'ne'): string
    {
        return $locale === 'en' ? $this->sourceTypeLabelEn : $this->sourceTypeLabelNe;
    }
}

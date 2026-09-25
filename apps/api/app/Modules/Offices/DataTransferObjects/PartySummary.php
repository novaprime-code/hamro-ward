<?php

declare(strict_types=1);

namespace App\Modules\Offices\DataTransferObjects;

use App\Modules\Offices\Models\Party;

/**
 * A party as a seat row shows it: name, abbreviation, link. No colour, by
 * design (NFR-NEU-02).
 */
final readonly class PartySummary
{
    public function __construct(
        public string $id,
        public string $slug,
        public ?string $nameNe,
        public ?string $nameEn,
        public ?string $abbreviationNe,
        public ?string $abbreviationEn,
    ) {}

    public static function fromModel(Party $party): self
    {
        return new self(
            id: $party->id,
            slug: $party->slug,
            nameNe: $party->name_ne,
            nameEn: $party->name_en,
            abbreviationNe: $party->abbreviation_ne,
            abbreviationEn: $party->abbreviation_en,
        );
    }

    public function name(string $locale = 'ne'): string
    {
        return $locale === 'en'
            ? (string) ($this->nameEn ?? $this->nameNe)
            : (string) ($this->nameNe ?? $this->nameEn);
    }

    public function abbreviation(string $locale = 'ne'): ?string
    {
        return $locale === 'en'
            ? ($this->abbreviationEn ?? $this->abbreviationNe)
            : ($this->abbreviationNe ?? $this->abbreviationEn);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Offices\DataTransferObjects;

use App\Modules\Offices\Models\Person;

/**
 * The little about a person a seat row needs: a name, a link, a photo.
 *
 * Deliberately not the model. A seat row travels to the API layer and into a
 * cache, and a Person model carries relations and, in future, unpublished
 * fields; a flat summary carries only what a ward page is allowed to show.
 */
final readonly class PersonSummary
{
    public function __construct(
        public string $id,
        public string $slug,
        public ?string $nameNe,
        public ?string $nameEn,
        public ?string $photoMediaId,
    ) {}

    public static function fromModel(Person $person): self
    {
        return new self(
            id: $person->id,
            slug: $person->slug,
            nameNe: $person->full_name_ne,
            nameEn: $person->full_name_en,
            photoMediaId: $person->photo_media_id,
        );
    }

    public function name(string $locale = 'ne'): string
    {
        return $locale === 'en'
            ? (string) ($this->nameEn ?? $this->nameNe)
            : (string) ($this->nameNe ?? $this->nameEn);
    }
}

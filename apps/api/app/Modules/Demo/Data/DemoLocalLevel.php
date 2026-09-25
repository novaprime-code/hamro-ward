<?php

declare(strict_types=1);

namespace App\Modules\Demo\Data;

use App\Modules\Geography\Enums\LocalLevelType;

/**
 * One invented local level and the real province and district it sits in
 * (docs/13 §2).
 *
 * The province and district are real because the administrative hierarchy is
 * the thing being demonstrated, and inventing it would misrepresent how Nepal
 * is organised. The local level is invented so that no real ward ever appears
 * with invented representatives.
 */
final readonly class DemoLocalLevel
{
    /**
     * @param  string  $key  stable identifier; deterministic UUIDs derive from it
     * @param  list<string>  $surnames  surnames plausible for this part of Nepal
     */
    public function __construct(
        public string $key,
        public string $nameNe,
        public string $nameEn,
        public LocalLevelType $type,
        public string $districtSlug,
        public string $districtNameNe,
        public string $districtNameEn,
        public string $provinceSlug,
        public string $provinceNameNe,
        public string $provinceNameEn,
        public int $wards,
        public int $population,
        public string $taglineNe,
        public string $taglineEn,
        public array $surnames,
    ) {}

    public function slug(): string
    {
        return $this->key;
    }

    /** province/district/local-level — what the public URL will carry. */
    public function slugPath(): string
    {
        return "{$this->provinceSlug}/{$this->districtSlug}/{$this->key}";
    }

    /**
     * How many wards get representatives seeded. The primary local level is
     * filled completely; the others get enough for their pages to look alive
     * without seeding several hundred people nobody will open.
     */
    public function seededWards(): int
    {
        return $this->key === DemoDataset::PRIMARY ? $this->wards : min(3, $this->wards);
    }
}

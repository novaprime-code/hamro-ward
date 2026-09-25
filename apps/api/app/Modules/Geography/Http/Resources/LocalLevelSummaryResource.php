<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Resources;

use App\Modules\Geography\Models\AdminUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A municipality as the picker lists it: enough to recognise and to link to,
 * and nothing more.
 *
 * Names ship in both scripts rather than pre-resolved for one locale, because
 * the same payload serves the Nepali and English sites and a citizen searching
 * in one script must match a name written in the other (FR-GEO-07).
 *
 * @property array{unit: AdminUnit, wards: int} $resource
 */
final class LocalLevelSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AdminUnit $unit */
        $unit = $this->resource['unit'];
        $district = $unit->parent;
        $province = $district?->parent;

        return [
            'slug_path' => $this->slugPath($unit),
            'name' => ['ne' => $unit->name_ne, 'en' => $unit->name_en],
            'type' => $unit->local_level_type?->value,
            'district' => ['ne' => $district?->name_ne, 'en' => $district?->name_en],
            'province' => ['ne' => $province?->name_ne, 'en' => $province?->name_en],
            'wards' => $this->resource['wards'],
        ];
    }

    private function slugPath(AdminUnit $unit): string
    {
        return implode('/', array_filter([
            $unit->parent?->parent?->slug,
            $unit->parent?->slug,
            $unit->slug,
        ]));
    }
}

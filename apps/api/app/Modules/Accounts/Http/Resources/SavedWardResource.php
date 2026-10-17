<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Resources;

use App\Modules\Accounts\Models\UserWard;
use App\Modules\Geography\Models\AdminUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One saved ward, as the account screens list it (HW-E30-F02-T01, docs/12 §12).
 *
 * Names ship in both scripts, as everywhere else: one payload serves both
 * sites (FR-GEO-07).
 *
 * `open` says whether Hamro Ward is live in that municipality yet. A ward may
 * be saved before it is (`SaveUserWard` refuses to pretend otherwise), and the
 * screen says "Not open yet" rather than silently listing a row that does
 * nothing.
 *
 * @property UserWard $resource
 */
final class SavedWardResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ward = $this->resource->ward;
        $localLevel = $ward?->parent;

        return [
            'id' => $this->resource->id,
            'ward_number' => $ward?->ward_number,
            'slug_path' => $localLevel === null ? null : $this->slugPath($localLevel),
            'local_level' => [
                'name' => ['ne' => $localLevel?->name_ne, 'en' => $localLevel?->name_en],
                'type' => $localLevel?->local_level_type?->value,
            ],
            'relationship' => $this->resource->relationship->value,
            'is_primary' => $this->resource->is_primary,
            'can_be_primary' => $this->resource->relationship->canBePrimary(),
            'open' => $this->resource->isInOpenMunicipality(),
            'saved_at' => $this->resource->created_at->toIso8601String(),
        ];
    }

    private function slugPath(AdminUnit $localLevel): string
    {
        return implode('/', array_filter([
            $localLevel->parent?->parent?->slug,
            $localLevel->parent?->slug,
            $localLevel->slug,
        ]));
    }
}

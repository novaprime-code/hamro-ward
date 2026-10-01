<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/published-paths  (HW-E03-F02-T02)
 *
 * Every address the public site has a real page for, so the web tier can build
 * a sitemap without asking 63 separate questions.
 *
 * Central only and outside the tenant group: the answer spans every
 * municipality, which is exactly the shape no tenant connection can produce.
 *
 * Published-and-open only. A municipality that exists in the data but has no
 * active tenant has no page to crawl, and listing it would have search engines
 * indexing 404s — and, worse, would leak which municipalities are being
 * prepared before anyone has agreed they are ready to be seen.
 *
 * `updated_at` is the unit's own, which is what changes when a name or a
 * publication state changes. It is honest rather than precise: the seats on a
 * ward page can change without the ward row moving. A crawler treats lastmod as
 * a hint, and a hint that is sometimes early is better than one that is
 * invented.
 */
final class PublishedPathsController
{
    public function __invoke(): JsonResponse
    {
        $openLocalLevelIds = Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->pluck('admin_unit_id');

        if ($openLocalLevelIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $localLevels = AdminUnit::query()
            ->publiclyVisible()
            ->where('level', AdminLevel::LocalLevel->value)
            ->whereIn('id', $openLocalLevelIds)
            ->with(['parent.parent'])
            ->get();

        $wards = AdminUnit::query()
            ->publiclyVisible()
            ->where('level', AdminLevel::Ward->value)
            ->whereIn('parent_id', $localLevels->pluck('id'))
            ->get()
            ->groupBy('parent_id');

        $data = $localLevels
            ->map(function (AdminUnit $unit) use ($wards): array {
                $path = implode('/', array_filter([
                    $unit->parent?->parent?->slug,
                    $unit->parent?->slug,
                    $unit->slug,
                ]));

                return [
                    'slug_path' => $path,
                    'updated_at' => $unit->updated_at?->toIso8601String(),
                    'wards' => $wards
                        ->get($unit->id, collect())
                        ->sortBy('ward_number')
                        ->map(fn (AdminUnit $ward): array => [
                            'number' => (int) $ward->ward_number,
                            'updated_at' => $ward->updated_at?->toIso8601String(),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('slug_path')
            ->values();

        return response()->json(['data' => $data->all()]);
    }
}

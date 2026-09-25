<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Http\Resources\LocalLevelSummaryResource;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Geography\Queries\PublishedLocalLevels;
use App\Modules\Offices\Http\Resources\SeatResource;
use App\Modules\Offices\Queries\CurrentSeatsQuery;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two pages before a ward: choose a municipality, then choose a ward
 * (FR-GEO-01, FR-GEO-02).
 */
final class LocalLevelController
{
    /**
     * GET /api/v1/local-levels — everything the picker needs, in one response.
     *
     * Not paginated. There are four municipalities today and 753 at the
     * theoretical maximum, which is a small enough payload to send whole and
     * let the client filter as the visitor types — far better on a slow
     * connection than a request per keystroke (§17).
     */
    public function index(Request $request, PublishedLocalLevels $localLevels): JsonResponse
    {
        $results = $localLevels->handle($request->query('q') === null ? null : (string) $request->query('q'));

        return response()->json([
            'data' => LocalLevelSummaryResource::collection($results)->resolve(),
        ]);
    }

    /**
     * GET /api/v1/local-levels/{province}/{district}/{local_level}
     *
     * The municipality page: its wards, and the seats elected by the whole
     * local level (mayor and deputy, or chair and vice-chair). Ward-level seats
     * belong to the ward pages.
     */
    public function show(Request $request, CurrentSeatsQuery $seats): JsonResponse
    {
        /** @var AdminUnit $central */
        $central = $request->attributes->get('localLevel');
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        // Inside the tenant already, courtesy of ResolveTenant.
        $localLevel = TenantAdminUnit::localLevel();

        if ($localLevel === null) {
            abort(503, 'This municipality has not finished syncing.');
        }

        $wards = TenantAdminUnit::query()
            ->wards()
            ->publiclyVisible()
            ->get()
            ->map(fn (TenantAdminUnit $ward): array => [
                'number' => $ward->ward_number,
                'name' => ['ne' => $ward->name_ne, 'en' => $ward->name_en],
                'slug_path' => $ward->slug_path,
            ]);

        $leadership = $seats->forConstituency($localLevel->id);

        return response()->json([
            'data' => [
                'slug_path' => $this->slugPathOf($central),
                'name' => ['ne' => $central->name_ne, 'en' => $central->name_en],
                'type' => $central->local_level_type?->value,
                'district' => [
                    'ne' => $central->parent?->name_ne,
                    'en' => $central->parent?->name_en,
                ],
                'province' => [
                    'ne' => $central->parent?->parent?->name_ne,
                    'en' => $central->parent?->parent?->name_en,
                ],
                'tenant_key' => $tenant->tenant_key,
                'wards' => $wards,
                'leadership' => SeatResource::collection($leadership)->resolve(),
                'coverage' => $seats->coverageFor($localLevel->id),
            ],
        ]);
    }

    private function slugPathOf(AdminUnit $unit): string
    {
        return implode('/', array_filter([
            $unit->parent?->parent?->slug,
            $unit->parent?->slug,
            $unit->slug,
        ]));
    }
}

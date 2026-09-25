<?php

declare(strict_types=1);

namespace App\Modules\Offices\Http\Controllers;

use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Offices\Http\Resources\SeatResource;
use App\Modules\Offices\Http\Resources\WardOfficeResource;
use App\Modules\Offices\Models\WardOffice;
use App\Modules\Offices\Queries\CurrentSeatsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The ward page: "who represents ward 4, and how do I know?"
 * (FR-OFF-02, FR-OFF-04, FR-GEO-08).
 */
final class WardController
{
    /**
     * GET /api/v1/wards/{province}/{district}/{local_level}/{ward}
     *
     * Returns BOTH constituencies a voter in this ward fills: the ward's own
     * five seats and the two elected by the whole local level (docs/02 §4.1).
     * They arrive in one list, each row carrying its constituency_level, so the
     * page can label which is which — showing the mayor under a ward heading
     * would imply the mayor is a ward official.
     */
    public function show(Request $request, CurrentSeatsQuery $seats): JsonResponse
    {
        /** @var AdminUnit $central */
        $central = $request->attributes->get('localLevel');

        $wardNumber = (int) $request->route('ward');

        $localLevel = TenantAdminUnit::localLevel();
        $ward = TenantAdminUnit::query()
            ->publiclyVisible()
            ->where('level', 'ward')
            ->where('ward_number', $wardNumber)
            ->first();

        if ($localLevel === null || $ward === null) {
            throw new NotFoundHttpException("Ward {$wardNumber} is not published in this municipality.");
        }

        $wardOffice = WardOffice::query()->where('ward_id', $ward->id)->first();

        return response()->json([
            'data' => [
                'ward_number' => $ward->ward_number,
                'slug_path' => $ward->slug_path,
                'name' => ['ne' => $ward->name_ne, 'en' => $ward->name_en],
                'local_level' => [
                    'slug_path' => $this->slugPathOf($central),
                    'name' => ['ne' => $central->name_ne, 'en' => $central->name_en],
                    'type' => $central->local_level_type?->value,
                    'district' => ['ne' => $central->parent?->name_ne, 'en' => $central->parent?->name_en],
                    'province' => [
                        'ne' => $central->parent?->parent?->name_ne,
                        'en' => $central->parent?->parent?->name_en,
                    ],
                ],
                'seats' => SeatResource::collection(
                    $seats->forWardPage($ward->id, $localLevel->id),
                )->resolve(),
                'coverage' => $seats->coverageFor($ward->id),
                'ward_office' => $wardOffice === null
                    ? null
                    : (new WardOfficeResource($wardOffice))->resolve(),
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

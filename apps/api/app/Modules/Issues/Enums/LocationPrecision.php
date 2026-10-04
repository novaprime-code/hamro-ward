<?php

declare(strict_types=1);

namespace App\Modules\Issues\Enums;

/**
 * How precisely a report's location is shown in public (FR-ISS-09). The stored point is exact; rounding happens in the API.
 */
enum LocationPrecision: string
{
    case Exact = 'exact';
    case Approximate = 'approximate';
    case WardOnly = 'ward_only';
}

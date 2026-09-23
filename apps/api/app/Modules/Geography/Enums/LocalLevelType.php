<?php

declare(strict_types=1);

namespace App\Modules\Geography\Enums;

enum LocalLevelType: string
{
    case MetropolitanCity = 'metropolitan_city';
    case SubMetropolitanCity = 'sub_metropolitan_city';
    case Municipality = 'municipality';
    case RuralMunicipality = 'rural_municipality';
}

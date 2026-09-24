<?php

declare(strict_types=1);

namespace App\Modules\Geography\Enums;

/**
 * External code systems for administrative units (docs/02 R4, 05 §3.2).
 */
enum CodeScheme: string
{
    case CbsCensus2021 = 'cbs_census_2021';
    case Ecn = 'ecn';
    case Mofaga = 'mofaga';
    case SurveyDept = 'survey_dept';
    case LegacyProvinceNo = 'legacy_province_no';
}

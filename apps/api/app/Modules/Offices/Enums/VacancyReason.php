<?php

declare(strict_types=1);

namespace App\Modules\Offices\Enums;

/**
 * Why a seat is vacant.
 *
 * NoCandidate is its own reason because it is a real and common outcome: no one
 * stood for the reserved Dalit woman ward-member seat in 123 local levels in
 * 2022 (docs/02 §4.2). A page must be able to say that, rather than showing an
 * empty row that reads like missing data.
 */
enum VacancyReason: string
{
    case NeverFilled = 'never_filled';
    case NoCandidate = 'no_candidate';
    case Resignation = 'resignation';
    case Death = 'death';
    case Removal = 'removal';
    case Suspension = 'suspension';
    case Other = 'other';
}

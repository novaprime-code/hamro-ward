<?php

declare(strict_types=1);

namespace App\Modules\Offices\Enums;

/** How someone comes to hold the position. Appointed staff are not positions. */
enum AppointmentType: string
{
    case ElectedDirect = 'elected_direct';
    case ElectedIndirect = 'elected_indirect';
}

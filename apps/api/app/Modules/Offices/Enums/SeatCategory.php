<?php

declare(strict_types=1);

namespace App\Modules\Offices\Enums;

/**
 * Which category of seat a position fills (docs/02 §4.1).
 *
 * This describes the SEAT, never the person. Open seats are open: women are
 * elected to them routinely, and nothing in the platform may infer a person's
 * gender or caste from the seat they hold (R9, FR-OFF-05).
 */
enum SeatCategory: string
{
    case Open = 'open';
    case Woman = 'woman';
    case DalitWoman = 'dalit_woman';
    case DalitMinority = 'dalit_minority';

    public function isReserved(): bool
    {
        return $this !== self::Open;
    }
}

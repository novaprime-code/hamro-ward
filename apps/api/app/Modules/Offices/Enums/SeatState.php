<?php

declare(strict_types=1);

namespace App\Modules\Offices\Enums;

/**
 * What the platform can honestly say about a seat right now (docs/05 §5.5).
 *
 * NotVerified is the default and covers three different situations that must
 * never be presented as one: nobody has entered the data, the data is entered
 * but unsourced, or the sources disagree. In all three the honest statement is
 * "we cannot confirm this", not "the seat is empty" (project instructions §3,
 * FR-SRC-02).
 */
enum SeatState: string
{
    case Held = 'held';
    case Vacant = 'vacant';
    case NotVerified = 'not_verified';
}

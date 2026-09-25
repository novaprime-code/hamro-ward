<?php

declare(strict_types=1);

namespace App\Modules\Offices\Enums;

/**
 * Why a holding ended (docs/02 §4.4). Terms end early often enough in Nepal —
 * officials had to resign to contest the March 2026 federal election — that
 * "current holder" cannot be inferred from the election date alone.
 */
enum HoldingEndReason: string
{
    case TermEnd = 'term_end';
    case Resignation = 'resignation';
    case Death = 'death';
    case Removal = 'removal';
    case Suspension = 'suspension';
    case Other = 'other';
}

<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Enums;

enum ConflictStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}

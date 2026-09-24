<?php

declare(strict_types=1);

namespace App\Modules\Geography\Enums;

enum LineageEvent: string
{
    case Restructure2017 = 'restructure_2017';
    case Merge = 'merge';
    case Split = 'split';
    case Rename = 'rename';
    case TypeChange = 'type_change';
}

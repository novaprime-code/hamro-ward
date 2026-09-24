<?php

declare(strict_types=1);

namespace App\Modules\Geography\Enums;

enum AliasKind: string
{
    case Legacy = 'legacy';
    case Variant = 'variant';
    case Misspelling = 'misspelling';
    case Abbreviation = 'abbreviation';
}

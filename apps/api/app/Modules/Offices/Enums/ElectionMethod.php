<?php

declare(strict_types=1);

namespace App\Modules\Offices\Enums;

/**
 * Direct — citizens vote for it. Indirect — the assembly elects it
 * (docs/02 §4.5). Only direct positions appear as seats on a ward page.
 */
enum ElectionMethod: string
{
    case Direct = 'direct';
    case Indirect = 'indirect';
}

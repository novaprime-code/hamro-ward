<?php

declare(strict_types=1);

namespace App\Modules\Issues\Enums;

/**
 * The language a report was written in.
 */
enum IssueLanguage: string
{
    case Ne = 'ne';
    case En = 'en';
    case Other = 'other';
}

<?php

declare(strict_types=1);

namespace App\Modules\Issues\Enums;

/**
 * The reporter's tie to the ward, snapshotted on the issue (docs/12 §12.3). Visible to moderators only, never public, never by itself a reason to reject.
 */
enum ReporterRelationship: string
{
    case PermanentAddress = 'permanent_address';
    case TemporaryAddress = 'temporary_address';
    case Workplace = 'workplace';
    case Other = 'other';
    case Visitor = 'visitor';
}

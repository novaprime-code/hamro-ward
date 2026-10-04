<?php

declare(strict_types=1);

namespace App\Modules\Issues\Enums;

/**
 * What has happened to the reported problem (docs/05 §6.2, FR-ISS-08). Only meaningful to the public once the report is approved.
 */
enum LifecycleStatus: string
{
    case Open = 'open';
    case CommunityConfirmed = 'community_confirmed';
    case ReportedToAuthority = 'reported_to_authority';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
}

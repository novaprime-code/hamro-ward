<?php

declare(strict_types=1);

namespace App\Modules\Issues\Enums;

/**
 * Where a report is in moderation (docs/05 §6.2). Separate from LifecycleStatus: whether the public may see a report and what happened to the problem are different questions.
 */
enum ModerationState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsReview = 'needs_review';
    case Flagged = 'flagged';
    case Archived = 'archived';
}

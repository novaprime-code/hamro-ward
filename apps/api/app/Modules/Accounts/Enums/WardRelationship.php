<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * Why a ward matters to a citizen (docs/12 §12.2).
 *
 * Four values, and the distinction between the first two is the reason the
 * account exists at all: a great many Nepalis are registered in one ward and
 * live in another, and a platform that recognised only one of those would ask
 * most of its users to choose which half of their life to report on.
 *
 * The relationship is stored on an issue as a snapshot (`reporter_relationship`)
 * and is visible to moderators only — context for judging a report, never a
 * public label on a person, and never on its own a reason to reject one
 * (docs/12 §12.3).
 */
enum WardRelationship: string
{
    case PermanentAddress = 'permanent_address';
    case TemporaryAddress = 'temporary_address';
    case Workplace = 'workplace';
    case Other = 'other';

    /**
     * Only a lived-in ward can be primary. A workplace is somewhere you have
     * standing to report on; it is not where you are from, and defaulting a
     * citizen's home ward to their office would be wrong in a way they would
     * have to notice to correct.
     */
    public function canBePrimary(): bool
    {
        return $this === self::PermanentAddress || $this === self::TemporaryAddress;
    }
}

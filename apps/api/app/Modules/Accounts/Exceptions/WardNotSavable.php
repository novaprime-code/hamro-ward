<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Exceptions;

use RuntimeException;

/**
 * The ward itself cannot be saved — wrong level, closed, or already saved
 * under this relationship.
 *
 * Separate from WardLimitReached because the remedy is different: a cap is
 * about the account, this is about the ward that was chosen.
 */
final class WardNotSavable extends RuntimeException
{
    public static function notAWard(): self
    {
        return new self('That is not a ward.');
    }

    public static function closed(): self
    {
        return new self('That ward no longer exists. It may have been merged or renumbered.');
    }

    public static function alreadySaved(): self
    {
        return new self('That ward is already saved under this relationship.');
    }

    public static function cannotBePrimary(): self
    {
        /*
         * A workplace is somewhere you have standing to report on; it is not
         * where you are from. Defaulting someone's home ward to their office is
         * wrong in a way they would have to notice in order to correct.
         */
        return new self('Only a permanent or temporary address can be the primary ward.');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Staff\Exceptions;

use RuntimeException;

final class StaffAuthorityException extends RuntimeException
{
    public static function notOperatorAdmin(): self
    {
        return new self('Only an operator admin can grant or revoke staff memberships.');
    }

    public static function inactive(): self
    {
        return new self('An inactive staff account cannot be given a membership.');
    }
}

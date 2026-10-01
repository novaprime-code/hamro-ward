<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * Whether an account can be used (docs/12 §12.1).
 *
 * DeletedPending rather than deleting the row: a citizen who asks to leave has
 * reports in moderation queues and published issues across several
 * municipalities, and those have to be withdrawn or detached before the account
 * can go (HW-E30-F03-T03). Until that finishes the account must not be usable
 * and must not be re-registerable under the same address.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
    case DeletedPending = 'deleted_pending';

    public function canSignIn(): bool
    {
        return $this === self::Active;
    }
}

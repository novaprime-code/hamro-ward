<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Actions\Fortify;

use App\Modules\Staff\Models\StaffUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\Rules\Password;

/**
 * Minimum ten characters for citizens (docs/12 §11.3), twelve for staff,
 * whose accounts can change what the public reads.
 */
final class PasswordRules
{
    /** @return list<mixed> */
    public static function for(?Authenticatable $account): array
    {
        return ['required', 'string', Password::min($account instanceof StaffUser ? 12 : 10)->max(500), 'confirmed'];
    }
}

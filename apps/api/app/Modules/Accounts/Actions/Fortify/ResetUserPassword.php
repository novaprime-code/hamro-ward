<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Actions\Fortify;

use App\Modules\Accounts\Models\User;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * @param  User|StaffUser  $user
     * @param  array<string, string>  $input
     */
    public function reset($user, array $input): void
    {
        Validator::make($input, ['password' => PasswordRules::for($user)])->validate();

        // Both models cast password as `hashed`.
        $user->forceFill(['password' => $input['password']])->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Actions\Fortify;

use App\Modules\Accounts\Models\User;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * @param  User|StaffUser  $user
     * @param  array<string, string>  $input
     */
    public function update($user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:'.config('fortify.guard')],
            'password' => PasswordRules::for($user),
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => $input['password']])->save();
    }
}

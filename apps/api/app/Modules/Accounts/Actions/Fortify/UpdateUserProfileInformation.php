<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Actions\Fortify;

use App\Modules\Accounts\Models\User;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * A citizen may change their display name and preferred language; a member
 * of staff, their name. Changing a sign-in address needs re-authentication
 * and re-verification (docs/12 §11.3) and is not offered here yet.
 */
final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  User|StaffUser  $user
     * @param  array<string, string>  $input
     */
    public function update($user, array $input): void
    {
        if ($user instanceof StaffUser) {
            $data = Validator::make($input, ['name' => ['required', 'string', 'min:2', 'max:100']])->validate();
            $user->forceFill(['name' => trim($data['name'])])->save();

            return;
        }

        $data = Validator::make($input, [
            'display_name' => ['required', 'string', 'min:2', 'max:60'],
            'preferred_locale' => ['required', 'in:ne,en'],
        ])->validateWithBag('updateProfileInformation');

        $user->forceFill(['display_name' => trim($data['display_name']), 'preferred_locale' => $data['preferred_locale']])->save();
    }
}

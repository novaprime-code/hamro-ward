<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Providers;

use App\Modules\Accounts\Actions\Fortify\AuthenticateAccount;
use App\Modules\Accounts\Actions\Fortify\ResetUserPassword;
use App\Modules\Accounts\Actions\Fortify\UpdateUserPassword;
use App\Modules\Accounts\Actions\Fortify\UpdateUserProfileInformation;
use App\Modules\Accounts\Listeners\StampStaffSignIn;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

/**
 * Wires Fortify to this application's accounts (docs/12 §11, D-033).
 * The host decides which accounts; see ConfigureAuthForHost.
 */
final class AccountsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::authenticateUsing(fn (Request $request) => app(AuthenticateAccount::class)($request));
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);

        /*
         * Per address and account. The staff LOCKOUT (5 failures, 15 minutes)
         * is in AuthenticateAccount; this is the ceiling for everyone.
         */
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(10)->by(
            mb_strtolower((string) $request->input('email')).'|'.$request->ip(),
        ));

        // Six digits must not be guessable at leisure: five tries a minute
        // per pending sign-in.
        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)->by(
            (string) $request->session()->get('login.id', $request->ip()),
        ));

        Event::listen(Login::class, StampStaffSignIn::class);
    }
}

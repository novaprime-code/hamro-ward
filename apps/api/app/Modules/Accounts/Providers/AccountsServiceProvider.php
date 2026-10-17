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
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
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

        /*
         * Sign-up, by address and by caller. Five an hour per address stops
         * the "someone tried to sign up" mail from being turned into a way of
         * pestering a mailbox; twenty an hour per address block is loose
         * enough for a household or an office on one connection.
         */
        RateLimiter::for('register', fn (Request $request): array => [
            Limit::perHour(5)->by(mb_strtolower((string) $request->input('email'))),
            Limit::perHour(20)->by((string) $request->ip()),
        ]);

        // The same reasoning for "send the link again", which mails on demand.
        RateLimiter::for('verification', fn (Request $request): array => [
            Limit::perHour(6)->by(mb_strtolower((string) $request->input('email', $request->user()?->getAuthIdentifier()))),
            Limit::perHour(20)->by((string) $request->ip()),
        ]);

        /*
         * "We have emailed your password reset link." — whatever happened.
         *
         * Fortify answers an unknown address with 422 "We can't find a user
         * with that email address", which is the same question sign-up
         * refuses to answer, asked at a different door. The broker's own
         * 60-second throttle leaks it too, so both failures take the success
         * response. The person who owns the mailbox still learns everything
         * they need; nobody else learns anything.
         */
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, fn (): Responsable => new class implements Responsable
        {
            public function toResponse($request): JsonResponse
            {
                return new JsonResponse(['message' => trans(Password::RESET_LINK_SENT)], 200);
            }
        });

        Event::listen(Login::class, StampStaffSignIn::class);
    }
}

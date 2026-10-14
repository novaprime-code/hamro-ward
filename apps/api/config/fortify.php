<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Fortify — sign-in for both kinds of account (docs/12 §11, D-033)
|--------------------------------------------------------------------------
|
| One set of Fortify routes serves two hosts. The values below are the
| PUBLIC host's; ConfigureAuthForHost swaps guard and passwords to the staff
| ones on the admin host, per request, before any session starts.
|
| auth_middleware is `auth.host` rather than `auth`: Fortify builds its route
| middleware strings when routes load, so `auth:web` would be fixed for both
| hosts. AuthenticateForHost ignores the parameter and authenticates against
| the guard the host selected.
|
| JSON only (views off): the web tier renders every screen and talks to
| these endpoints same-origin, through its rewrites.
|
| Two flows are NOT Fortify's, and are routed in routes/web.php instead:
|
|   registration        Fortify creates the account and signs the person in,
|                       and both of those answer "is this address known?" to
|                       anyone who asks. See RegisterController.
|   email verification  Fortify's verify route needs a signed-in session, and
|                       sign-up deliberately leaves nobody signed in. See
|                       VerifyEmailController.
*/

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,

    'home' => '/',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'auth_middleware' => 'auth.host',

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    'paths' => [],
    'redirects' => [],

    'views' => false,

    'features' => [
        Features::resetPasswords(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            'window' => 1,
        ]),
    ],
];

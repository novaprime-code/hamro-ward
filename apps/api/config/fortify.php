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
| Registration is off until citizen sign-up exists with Turnstile and an
| enumeration-safe response (HW-E30-F01-T02).
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
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            'window' => 1,
        ]),
    ],
];

<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Fortify
|--------------------------------------------------------------------------
| The values here are the CITIZEN defaults. They are not the whole story.
|
| Hamro Ward serves two account types from one application, on two hosts
| (docs/12 §11.1). Three of the keys below are overridden per request by
| ConfigureAuthForHost, and two more are overridden per host at route
| registration by AuthServiceProvider. Which key belongs to which is not a
| detail — it is the finding of spike HW-E30-F01-T01, written up in D-018:
|
|   guard, passwords, home     read at request time   → ConfigureAuthForHost
|   features                   read at registration   → AuthServiceProvider
|   middleware, prefix, views  read at registration   → fixed for both hosts
|
| Anything in the second group cannot be switched per request, because it
| decides what the route table contains rather than what a route does.
*/

return [

    /*
    | Citizen guard and broker. The admin host uses `staff` for both; see
    | AuthContext.
    */
    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    /*
    | Nepali addresses arrive with mixed case from phone keyboards that
    | capitalise the first letter, and an account must not depend on how the
    | keyboard felt. The column is citext, so the database agrees.
    */
    'lowercase_usernames' => true,

    'home' => '/',

    /*
    | No prefix: the endpoints are the ones docs/12 §11.3 lists — POST /login,
    | POST /register — proxied same-origin from both hosts, so they sit at the
    | root rather than under /api/v1.
    */
    'prefix' => '',

    /*
    | Null, and it must stay null. AuthServiceProvider puts each copy of the
    | route file inside its own Route::domain() group; a domain set here would
    | apply to both and defeat the separation.
    */
    'domain' => null,

    /*
    | The `web` group — session, cookies, CSRF. ConfigureAuthForHost is
    | prepended to that group in bootstrap/app.php so it runs before
    | StartSession, which is the one ordering requirement in the whole design.
    */
    'middleware' => ['web'],

    'limiters' => [
        /*
         | docs/12 §11.3: 5 attempts a minute per email plus IP. Both limiters
         | are defined in SecurityServiceProvider, keyed on the submitted
         | address and the client address together, so one attacker cannot lock
         | a real person out of their own account by guessing at it.
         */
        'login' => 'login',
        'two-factor' => 'two-factor',
        'verification' => 'verification',
    ],

    /*
    | False: this application renders no HTML. Next.js serves every screen and
    | talks to these endpoints over the same origin, so Fortify's view routes
    | would be dead paths that only widen the surface.
    */
    'views' => false,

    /*
    | The citizen set. AuthServiceProvider replaces this while registering each
    | host's routes — the admin host gets no registration, no password-reset
    | request and no email verification, because staff accounts are invite-only
    | (docs/12 §11.1). Listed here for the reader; AuthContext is where the two
    | sets are actually decided.
    */
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],

];

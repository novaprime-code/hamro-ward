<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\User;
use App\Modules\Staff\Models\StaffUser;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    /*
    | Two session guards, one per host (docs/12 §11.1).
    |
    | `defaults.guard` above is only what a request on an unrecognised host
    | would get: ConfigureAuthForHost overwrites both defaults per request from
    | the hostname, so `web` applies on the public host and `staff` on the
    | admin host. Nothing should rely on the default — the host decides.
    */
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'staff' => [
            'driver' => 'session',
            'provider' => 'staff_users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        /*
        | Staff are a different table, not a flag on `users` (docs/12 §11.1).
        | The same person may hold both kinds of account; nothing joins them,
        | which is what keeps a moderator's privileges out of the account they
        | report from.
        |
        | No env() override here, unlike `users` above. A deployment that could
        | repoint the staff provider at another model through an environment
        | variable is a deployment where a typo decides who can moderate.
        */
        'staff_users' => [
            'driver' => 'eloquent',
            'model' => StaffUser::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        /*
        | Defined, but no route reaches it: the admin host registers neither
        | forgot-password nor reset-password, because a reset form on the host
        | that holds moderation is a wider surface than asking an operator
        | admin (docs/12 §11.1, AuthContext::fortifyFeatures).
        |
        | It exists because ConfigureAuthForHost points
        | `auth.defaults.passwords` at this broker on the admin host, and a
        | broker named in config but missing from it fails the first time
        | anything resolves it rather than at deploy.
        |
        | Its own token table, not the citizens' one: `password_reset_tokens`
        | is keyed by email alone, so sharing it would let a token issued for a
        | citizen account be presented for a staff account with the same
        | address.
        */
        'staff_users' => [
            'provider' => 'staff_users',
            'table' => 'staff_password_reset_tokens',
            'expire' => 30,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];

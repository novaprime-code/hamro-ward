<?php

declare(strict_types=1);

use App\Modules\Auth\Support\AuthHosts;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Http\Middleware\EncryptCookies;
use Laravel\Sanctum\Http\Middleware\ValidateCsrfToken;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful domains
    |--------------------------------------------------------------------------
    | The hosts allowed to authenticate with a session cookie instead of a
    | token — exactly the public host and the admin host, and nothing else
    | (docs/12 §11.2).
    |
    | Built from config/hosts.php rather than from its own environment
    | variable, so the list cannot drift from the hosts the rest of the
    | application recognises. A hostname that is stateful here but unknown to
    | AuthHosts would accept a cookie and then have no guard to check it with.
    |
    | The optional `api.` host is deliberately absent. It exists for future
    | partner APIs and serves no cookie-authenticated route; adding it would
    | hand session authentication to the one host that must never have it.
    */
    'stateful' => AuthHosts::statefulDomains(),

    /*
    |--------------------------------------------------------------------------
    | Guard
    |--------------------------------------------------------------------------
    | Overwritten per request by ConfigureAuthForHost, which sets it to the
    | guard belonging to the request's host. Sanctum\Guard reads it at request
    | time, so switching works here — unlike Fortify's route middleware, which
    | is baked at registration (D-018).
    |
    | The value below is only what a request on an unrecognised host would
    | see, and the host gate refuses those before they reach anything that
    | needs a guard.
    */
    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration
    |--------------------------------------------------------------------------
    | Null: this application issues no API tokens. Authentication is cookie
    | sessions only, and session lifetime is config/session.php.
    */
    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];

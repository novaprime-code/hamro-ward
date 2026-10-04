<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Staff access (docs/12 §11.1, §11.5; HW-E13-F01-T02, T03)
|--------------------------------------------------------------------------
|
| admin_host   The host part of ADMIN_URL, which every stack already sets.
|              Staff routes answer 404 on every other host, and requests on
|              this host get the staff session cookie. Unset means staff
|              access is switched off: no host matches, so every staff route
|              is a 404.
|
| The rest are the rules HW-E13-F01-T03 fixes: five failed sign-ins lock the
| account for fifteen minutes; a session ends after thirty idle minutes and,
| whatever the activity, twelve hours after sign-in.
*/

return [
    'admin_host' => (string) parse_url((string) env('ADMIN_URL', ''), PHP_URL_HOST),

    'session_cookie' => 'hw_staff_session',
    'public_session_cookie' => 'hw_session',

    'max_failed_logins' => 5,
    'lockout_minutes' => 15,
    'idle_minutes' => 30,
    'absolute_hours' => 12,

    // A sign-in half done (password accepted, second step not yet) expires.
    'pending_minutes' => 10,

    'recovery_codes' => 8,
];

<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The two hosts this application answers on
|--------------------------------------------------------------------------
| Hamro Ward serves citizens and staff from the same Laravel application but
| on two different hostnames (docs/12 §11.2):
|
|   hamroward.<tld>         citizens: read, report, follow their wards
|   admin.hamroward.<tld>   staff: moderate, verify, manage data
|
| The host is not cosmetic. It decides which guard authenticates the request,
| which session cookie is read, and which routes exist at all. Two people
| signed in at once — one as a citizen, one as staff, in the same browser —
| must not be able to act as each other, and the only thing separating them
| is this pair of names plus host-only cookies.
|
| Both are stored without a scheme or a port: what arrives in the Host header
| is compared against them, and a port makes that comparison fail behind a
| proxy that terminates TLS on 443 and forwards to 8080.
|
| A request whose host matches NEITHER of these is not served as either a
| citizen or a staff request. It gets 404 from the host gate rather than being
| quietly treated as public — see AuthContext and RequireAuthContext. An
| unrecognised host is usually a misconfigured proxy, and the wrong answer to
| a misconfigured proxy is to hand it a session.
*/

return [

    /*
    | The public host. Citizens sign in here. Defaults to the host in
    | FRONTEND_URL so a local checkout works with no extra configuration.
    */
    'public' => env('HW_PUBLIC_HOST', parse_url((string) env('FRONTEND_URL', 'http://localhost:3000'), PHP_URL_HOST)),

    /*
    | The admin host. Staff sign in here, and nothing on it is indexable or
    | cacheable (docs/12 §11.4). Defaults to the host in ADMIN_URL.
    */
    'admin' => env('HW_ADMIN_HOST', parse_url((string) env('ADMIN_URL', 'http://admin.localhost:3000'), PHP_URL_HOST)),

];

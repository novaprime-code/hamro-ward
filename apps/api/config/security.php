<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Security posture at the edge of the API
|--------------------------------------------------------------------------
| Two settings that only make sense together: which callers may set the
| X-Forwarded-* headers that decide what $request->ip() returns, and how many
| requests a caller gets.
|
| The topology this describes (docs/06, docs/12 §11.2):
|
|   browser → Nginx Proxy Manager → web (Next.js) → app (Laravel)
|
| The browser never reaches Laravel. Next fetches the API over the stack's
| private network, so in practice this application has exactly one client, and
| a rate limit keyed on the client's address would put every visitor in the
| country into one bucket. The per-visitor ceiling therefore lives in the web
| tier (apps/web/src/middleware.ts); what is configured here is the backstop
| for the two cases that tier cannot cover — a runaway loop inside the network,
| and anything that reaches the API without going through the web tier at all.
*/

return [

    /*
    | Addresses whose X-Forwarded-* headers are believed.
    |
    | This was '*' — every caller trusted — which is safe only for as long as
    | nothing reads the client address. Rate limiting reads it, the audit log
    | will read it, and issue reports will record it (docs/12 §12). With '*',
    | any caller can set X-Forwarded-For to whatever it likes and every one of
    | those becomes a value chosen by the person it is supposed to identify.
    |
    | The default covers the RFC 1918 ranges a Docker network is allocated
    | from, which is where the web container sits. It does NOT cover the public
    | internet, so a request that somehow reaches this container directly is
    | identified by the address it actually came from.
    |
    | Cloudflare's ranges are deliberately absent: Cloudflare is DNS-only for
    | this deployment, so its addresses never appear, and trusting a range that
    | cannot legitimately appear only widens what can be forged. Add them here
    | on the day the orange cloud is switched on, and not before.
    |
    | '*' is still accepted, for a local machine where there is no proxy to
    | name. It should never be set on a deployed stack.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'),

    /*
    | Callers treated as the application's own web tier rather than as the
    | public. They get the loop ceiling below instead of the public limit.
    |
    | Broadly the same ranges as the trusted proxies, and for the same reason —
    | the web container is both — but kept separate because the two answer
    | different questions, and the day a CDN is added they stop agreeing.
    |
    | Loopback is included here and not above: a request arriving from
    | 127.0.0.1 originated inside this container, which is as internal as a
    | request gets, but a container talking to itself is not a proxy and has no
    | business renaming anyone.
    */
    'internal_clients' => env(
        'API_INTERNAL_CLIENTS',
        '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
    ),

    'throttle' => [

        /*
        | Anything reaching the API from outside the stack's own network.
        | Nothing should, today. A caller that does is either a developer with
        | a port open or somebody looking around, and neither needs much.
        */
        'public_read_per_minute' => (int) env('THROTTLE_PUBLIC_READ_PER_MINUTE', 120),

        /*
        | The web tier's ceiling. Set high enough that a real traffic spike
        | never touches it — every page the web tier serves is ISR-cached, so
        | its call rate is a function of how many distinct pages expire in a
        | minute, not of how many people are reading — and low enough that a
        | retry loop or a runaway job stops before it takes the database with
        | it.
        |
        | If this limit is ever actually reached, the answer is almost
        | certainly a bug in the web tier, not a number that needs raising.
        */
        'internal_read_per_minute' => (int) env('THROTTLE_INTERNAL_READ_PER_MINUTE', 3000),
    ],

];

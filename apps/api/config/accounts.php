<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Citizen accounts (docs/12 §11.3; HW-E30-F01-T02, T03)
|--------------------------------------------------------------------------
|
| frontend_url   Where a person reads their email and clicks. Every link this
|                application mails — verify your address, reset your password
|                — has to arrive at the web tier, which is the only side with
|                screens: this application answers JSON and nothing else
|                (fortify.views is false).
|
|                It also has to be the host the browser actually asks for,
|                because a signed verification link is signed over its whole
|                URL, host included, and the signature is checked against the
|                URL the request arrives on. APP_URL is this container's own
|                address inside the Docker network, which no browser ever
|                sees, so signing with it would fail every check.
|
| verify_hours   How long a verification link lives. A day, so a link opened
|                the next morning still works, and a leaked old mailbox is
|                not a standing key.
*/

return [
    'frontend_url' => rtrim((string) env('FRONTEND_URL', ''), '/'),

    'verify_hours' => 24,

    // The locale a mailed link lands on when the account has none.
    'fallback_locale' => 'ne',
];

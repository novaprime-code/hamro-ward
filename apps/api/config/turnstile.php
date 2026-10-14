<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cloudflare Turnstile — the bot check on citizen sign-up (docs/09 §5.6)
|--------------------------------------------------------------------------
|
| Sign-up is the one public endpoint that creates a durable record, so it is
| the one worth a challenge. Turnstile rather than a CAPTCHA because it asks
| nothing of the person: no traffic lights, no crosswalks, nothing to read in
| a language they may not have.
|
| WHEN NO SECRET IS SET the check is OFF, and registration works without a
| token. That is deliberate: a developer's machine and a CI run have no keys
| and must still be able to exercise sign-up end to end. It also means a
| deployment that forgets the keys quietly loses its bot protection, so
| `php artisan hw:doctor` reports it and so does the sign-up test below.
*/

return [
    'site_key' => (string) env('TURNSTILE_SITE_KEY', ''),
    'secret_key' => (string) env('TURNSTILE_SECRET_KEY', ''),

    'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',

    // Three seconds: a sign-up that waits longer than this on Cloudflare is
    // worse for the person than a sign-up that lets a bot through.
    'timeout_seconds' => 3,
];

<?php

declare(strict_types=1);

/*
| Signed on-demand revalidation of the web tier (HW-E08-F01-T04, docs/06 §11).
|
| url     the web container's /api/revalidate, over the internal network.
|         Empty: revalidation is off and pages refresh on their own timers.
| secret  shared with the web container's REVALIDATE_SECRET; HMAC-SHA256 key.
*/
return [
    'url' => env('REVALIDATE_URL', ''),
    'secret' => env('REVALIDATE_SECRET', ''),
    'timeout_seconds' => 5,
];

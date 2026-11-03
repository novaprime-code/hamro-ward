<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Photographs attached to reports (HW-E12-F01; docs/06 §7, FR-MED-01..04)
|--------------------------------------------------------------------------
|
| DISKS. Two, and the separation is the privacy rule rather than tidiness: a
| photograph stays unreachable until a moderator approves the report it
| belongs to (FR-MED-03). Everything lands on the private disk; nothing is
| copied to the public one yet, because nothing can approve a report until
| moderation exists (HW-E14). Both default to local storage, so a developer's
| machine and CI need no cloud account; a deployment points them at the two
| R2 buckets (HW-E12-F01-T03).
|
| LIMITS. Ten megabytes and eight thousand pixels, checked against the file's
| real content rather than its name. The browser is asked to downscale to
| 2,000px first (FR-MED-04), so anything near these numbers is a client that
| did not, or is not a browser at all.
|
| VARIANTS. Two WebP sizes: one for a list on a phone, one for the page. The
| original is deleted once they exist — it is the copy that carries the
| camera's metadata, and keeping it would mean keeping the thing the stripping
| is for.
*/

return [
    'private_disk' => (string) env('MEDIA_PRIVATE_DISK', 'local'),
    'public_disk' => (string) env('MEDIA_PUBLIC_DISK', 'public'),

    'max_bytes' => 10 * 1024 * 1024,
    'max_pixels' => 8000,

    /*
     * The allowlist, by magic bytes (FR-MED-01). HEIC is listed because the
     * requirement lists it; whether this runtime can actually decode one is a
     * separate question, asked by Support\ImageFile, because GD cannot and
     * refusing at upload is kinder than accepting and failing later.
     */
    'accepted' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],

    'variants' => [
        'sm' => 480,
        'lg' => 1600,
    ],

    // Quality on re-encode. 82 is where WebP stops being visibly lossy on a
    // photograph of a pothole and starts costing bytes a 3G connection pays for.
    'quality' => 82,

    'queue' => 'media',
];

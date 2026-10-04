<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells the web tier which cached pages are stale (HW-E08-F01-T04, docs/06 §11).
 *
 * POSTs {"tags": [...]} to REVALIDATE_URL, signed with HMAC-SHA256 over
 * "{timestamp}.{body}" in X-HW-Signature, the timestamp in X-HW-Timestamp.
 * The web route accepts it within five minutes of that timestamp. The
 * signature is made when the job runs, not when it is queued, so a retry
 * after a backoff is still inside the window.
 *
 * Tag sets are safe to repeat (docs/06 §7): sending one twice costs a
 * re-render, never a wrong page. So a failure is retried, and a failure that
 * outlasts the retries costs freshness only — every page still refreshes on its
 * own timer.
 */
final class DispatchRevalidation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param  list<string>  $tags */
    public function __construct(public readonly array $tags) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    public function handle(): void
    {
        $url = (string) config('revalidation.url');

        // Not configured: pages refresh on their own timers. Local development
        // and the test suite run this way.
        if ($url === '' || $this->tags === []) {
            return;
        }

        $secret = (string) config('revalidation.secret');

        if (strlen($secret) < 16 || preg_match('/change[-_]?me/i', $secret) === 1) {
            Log::warning('Revalidation skipped: REVALIDATE_SECRET is unset or still the placeholder.');

            return;
        }

        $body = json_encode(['tags' => array_values(array_unique($this->tags))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();

        Http::timeout((int) config('revalidation.timeout_seconds', 5))
            ->withHeaders([
                'X-HW-Timestamp' => $timestamp,
                'X-HW-Signature' => self::sign($timestamp, $body, $secret),
            ])
            ->withBody($body, 'application/json')
            ->post($url)
            ->throw();
    }

    public static function sign(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}

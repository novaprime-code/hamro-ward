<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The bot check in front of sign-up (config/turnstile.php).
 *
 * Two deliberate choices about failure:
 *
 *  - NO SECRET CONFIGURED means the check is off and every token passes. A
 *    developer's machine and a CI run have no Cloudflare keys and must still
 *    be able to sign an account up end to end.
 *
 *  - CLOUDFLARE UNREACHABLE means the token passes too, with a warning in the
 *    log. The alternative is that an outage at Cloudflare closes registration
 *    for the whole country, which is a worse failure than letting a few bots
 *    through a form that still has rate limits and email verification behind
 *    it. A refused token is refused; only an unanswered question is forgiven.
 */
final class Turnstile
{
    public function configured(): bool
    {
        return (string) config('turnstile.secret_key') !== '';
    }

    public function verify(?string $token, ?string $ip): bool
    {
        if (! $this->configured()) {
            return true;
        }

        if ($token === null || trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('turnstile.timeout_seconds'))
                ->post((string) config('turnstile.verify_url'), array_filter([
                    'secret' => (string) config('turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (Throwable $exception) {
            Log::warning('Turnstile could not be reached; letting the sign-up through.', [
                'exception' => $exception->getMessage(),
            ]);

            return true;
        }

        if ($response->serverError()) {
            Log::warning('Turnstile answered with an error; letting the sign-up through.', [
                'status' => $response->status(),
            ]);

            return true;
        }

        return $response->json('success') === true;
    }
}

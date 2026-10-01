<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Exceptions;

use RuntimeException;

/**
 * A citizen has hit one of the saved-ward caps (docs/12 §12.1, §12.4 rule 3).
 *
 * Carries which cap and what to say about it, because the two are different
 * answers to a citizen: "you already have five" is permanent until they remove
 * one, while "three in 30 days" clears on its own and the reply should say
 * when. A single "limit reached" would leave the first group waiting for
 * something that is never going to happen.
 */
final class WardLimitReached extends RuntimeException
{
    private function __construct(
        public readonly string $limit,
        string $message,
        public readonly ?string $clearsAt = null,
    ) {
        parent::__construct($message);
    }

    public static function tooManySaved(int $max): self
    {
        return new self(
            'saved_wards',
            "A maximum of {$max} wards can be saved. Remove one to add another.",
        );
    }

    public static function tooManyRecently(int $max, string $clearsAt): self
    {
        return new self(
            'additions_per_30_days',
            "A maximum of {$max} wards can be added in 30 days.",
            $clearsAt,
        );
    }
}

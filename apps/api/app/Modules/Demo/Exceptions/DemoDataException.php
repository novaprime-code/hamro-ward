<?php

declare(strict_types=1);

namespace App\Modules\Demo\Exceptions;

use RuntimeException;

/**
 * The demonstration seeder refused to act.
 *
 * Every message here is written for the person reading a failed deploy log at
 * an awkward hour, so each one says what to do next rather than only what went
 * wrong.
 */
final class DemoDataException extends RuntimeException
{
    public static function refusedInProduction(): self
    {
        return new self(
            'Refusing to seed demonstration data in production. '
            .'Invented representatives must never reach a live civic platform. '
            .'If this really is intended — a staging environment running with APP_ENV=production, say — '
            .'set HW_ALLOW_DEMO_DATA=true explicitly.'
        );
    }

    /** @param  list<string>  $collisions */
    public static function nameCollision(array $collisions): self
    {
        return new self(
            'A demonstration local level name matches a real published local level: '
            .implode(', ', $collisions).'. '
            .'Rename the demonstration local level in DemoDataset before seeding — '
            .'an invented municipality sharing a real name is exactly what this dataset exists to avoid.'
        );
    }

    public static function geographyMissing(string $slugPath): self
    {
        return new self(
            "Demonstration geography for {$slugPath} has not been created. Run hw:demo:seed without --tenants-only."
        );
    }

    public static function noPositions(): self
    {
        return new self(
            'The positions catalogue is empty, so no seats can be filled. Run php artisan db:seed first.'
        );
    }
}

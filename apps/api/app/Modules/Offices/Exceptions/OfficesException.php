<?php

declare(strict_types=1);

namespace App\Modules\Offices\Exceptions;

use RuntimeException;

/**
 * A write the offices module refused before it reached the database.
 *
 * The database enforces the same rules again — nothing here is the only line of
 * defence — but a constraint violation is a 500 with a PostgreSQL message,
 * while these become a readable 422 for the editor who made the mistake.
 */
final class OfficesException extends RuntimeException
{
    public static function unknownPerson(string $personId): self
    {
        return new self("Person {$personId} does not exist in the central database.");
    }

    public static function mergedPerson(string $personId, string $keptPersonId): self
    {
        return new self(
            "Person {$personId} was merged into {$keptPersonId}; record the holding against the kept person."
        );
    }

    public static function unknownParty(string $partyId): self
    {
        return new self("Party {$partyId} does not exist in the central database.");
    }

    public static function independentWithParty(): self
    {
        return new self('A holding is either independent or has a party, not both.');
    }

    public static function unknownPosition(string $positionKey): self
    {
        return new self(
            "Position {$positionKey} is not in this tenant's catalogue; run hw:tenant:sync-reference first."
        );
    }

    public static function seatAlreadyHeld(string $positionKey, int $seatIndex): self
    {
        return new self(
            "Seat {$seatIndex} of {$positionKey} already has a holder over part of that period."
        );
    }

    public static function holdingAlreadyEnded(string $holdingId): self
    {
        return new self("Holding {$holdingId} already has an end date.");
    }
}

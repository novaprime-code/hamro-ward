<?php

declare(strict_types=1);

namespace App\Modules\Geography\Support;

/**
 * Digits, in both scripts (§16, docs/09 §3.4).
 *
 * Nepali text uses Devanagari digits (०१२३४५६७८९) and Latin ones
 * interchangeably, often in the same sentence — a ward number printed on an
 * office sign is Devanagari, the same number typed into a phone is usually
 * Latin. Anything that reads a number a person typed has to accept both, or it
 * works for half its users and looks broken to the other half.
 */
final class Digits
{
    private const DEVANAGARI = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];

    /** Devanagari digits to Latin, leaving everything else alone. */
    public static function toLatin(string $text): string
    {
        return str_replace(self::DEVANAGARI, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);
    }

    /** Latin digits to Devanagari, for display in Nepali. */
    public static function toDevanagari(int|string $value): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            self::DEVANAGARI,
            (string) $value,
        );
    }
}

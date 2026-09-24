<?php

declare(strict_types=1);

namespace App\Modules\Geography\Support;

use Normalizer;

/**
 * Normalizes place and person names for matching (docs/06 §5, FR-GEO-07).
 * The output is for search keys only — never displayed.
 *
 * Rules:
 *  - Unicode NFC
 *  - Devanagari: drop nukta (़) and zero-width joiners; chandrabindu (ँ) → anusvara (ं)
 *  - lower-case; punctuation → space; collapse whitespace
 *  - romanized variants: ee → i, oo → u, doubled a/i/u collapsed, w → v
 */
final class NameNormalizer
{
    public static function normalize(string $name): string
    {
        $text = class_exists(Normalizer::class)
            ? (Normalizer::normalize($name, Normalizer::FORM_C) ?: $name)
            : $name;

        $text = str_replace(["\u{200C}", "\u{200D}", "\u{093C}"], '', $text);
        $text = str_replace("\u{0901}", "\u{0902}", $text);

        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', $text) ?? $text;

        $text = str_replace(['ee', 'oo', 'w'], ['i', 'u', 'v'], $text);
        $text = preg_replace('/([aiu])\1+/', '$1', $text) ?? $text;

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}

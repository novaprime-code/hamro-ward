<?php

declare(strict_types=1);

namespace App\Modules\Offices\Support;

use App\Modules\Offices\Models\Person;
use Illuminate\Support\Str;

/**
 * Builds a person's URL slug (docs/05 §5.2).
 *
 * Nepali names repeat: several people called Ram Bahadur Thapa will hold local
 * office at the same time. A slug of the name alone would collide and, worse,
 * would let one person's URL quietly start resolving to another. So every slug
 * carries a short random suffix — the name is for humans reading the URL, the
 * suffix is what makes it identify one person.
 *
 * Devanagari does not survive romanization here on purpose: transliterating it
 * badly produces a slug that is wrong in a way nobody notices. A person with no
 * Latin name gets `vyakti-<suffix>`, and the page title carries the real name.
 */
final class PersonSlug
{
    private const FALLBACK = 'vyakti';

    private const SUFFIX_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    public static function for(?string $latinName, ?string $suffix = null): string
    {
        $base = Str::slug((string) $latinName);

        if ($base === '') {
            $base = self::FALLBACK;
        }

        $base = Str::limit($base, 140, '');

        return trim($base, '-').'-'.($suffix ?? self::suffix());
    }

    /** Tries a handful of suffixes before giving up, rather than looping forever. */
    public static function unique(?string $latinName): string
    {
        foreach (range(1, 8) as $ignored) {
            $slug = self::for($latinName);

            if (! Person::query()->where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        return self::for($latinName, self::suffix().self::suffix());
    }

    /** Four characters from an alphabet without 0/o/1/l, which get mistyped. */
    private static function suffix(): string
    {
        $alphabet = self::SUFFIX_ALPHABET;
        $suffix = '';

        for ($i = 0; $i < 4; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffix;
    }
}

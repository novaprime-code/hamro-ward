<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Support;

/**
 * The cache tags the web tier attaches to its API fetches
 * (apps/web/src/lib/cache-tags.ts). The two must agree: a tag sent here that
 * no fetch carries refreshes nothing, and the web route refuses it.
 */
final class CacheTags
{
    /** Every public page. */
    public const PUBLIC = 'public';

    /** Lists spanning municipalities: the picker, published paths. */
    public const INDEX = 'index';

    /** Everything at one municipality's address. */
    public static function place(string $localLevelPath): string
    {
        return 'place:'.trim($localLevelPath, '/');
    }
}

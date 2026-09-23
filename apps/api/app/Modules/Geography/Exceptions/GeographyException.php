<?php

declare(strict_types=1);

namespace App\Modules\Geography\Exceptions;

use App\Modules\Geography\Models\AdminUnit;
use RuntimeException;

final class GeographyException extends RuntimeException
{
    public static function notCurrent(AdminUnit $unit): self
    {
        return new self("Administrative unit [{$unit->getKey()}] is historical and cannot be published.");
    }

    /**
     * @param  list<string>  $ancestorSlugs
     */
    public static function unpublishedAncestors(AdminUnit $unit, array $ancestorSlugs): self
    {
        $list = implode(', ', $ancestorSlugs);

        return new self("Publish these ancestors of [{$unit->slug}] first: {$list}.");
    }

    public static function slugPathTaken(string $path): self
    {
        return new self("Slug path [{$path}] already belongs to another administrative unit.");
    }
}

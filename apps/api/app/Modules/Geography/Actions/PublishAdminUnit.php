<?php

declare(strict_types=1);

namespace App\Modules\Geography\Actions;

use App\Modules\Geography\Exceptions\GeographyException;
use App\Modules\Geography\Models\AdminUnit;

/**
 * Editorial visibility gate (D-006, docs/05 §3.1). Publishing is top-down:
 * a unit can be published only when every ancestor already is.
 *
 * Audit events are added with AuditLogger (HW-E14-F01-T02); page revalidation
 * with DispatchRevalidation (HW-E08-F01-T04).
 */
final class PublishAdminUnit
{
    public function publish(AdminUnit $unit): AdminUnit
    {
        if (! $unit->isCurrent()) {
            throw GeographyException::notCurrent($unit);
        }

        $hidden = $unit->ancestors()
            ->filter(fn (AdminUnit $ancestor): bool => ! $ancestor->is_published || ! $ancestor->isCurrent())
            ->map(fn (AdminUnit $ancestor): string => $ancestor->slug)
            ->values()
            ->all();

        if ($hidden !== []) {
            throw GeographyException::unpublishedAncestors($unit, $hidden);
        }

        if (! $unit->is_published) {
            $unit->forceFill([
                'is_published' => true,
                'published_at' => now(),
            ])->save();
        }

        return $unit;
    }

    /**
     * Hides the unit. Descendants stay flagged as published but become
     * invisible through the publication rule until it is published again.
     */
    public function unpublish(AdminUnit $unit): AdminUnit
    {
        if ($unit->is_published) {
            $unit->forceFill([
                'is_published' => false,
                'published_at' => null,
            ])->save();
        }

        return $unit;
    }
}

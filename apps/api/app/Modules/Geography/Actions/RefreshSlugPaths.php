<?php

declare(strict_types=1);

namespace App\Modules\Geography\Actions;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Exceptions\GeographyException;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use Illuminate\Support\Facades\DB;

/**
 * Keeps admin_unit_slugs in step with unit slugs (FR-GEO-03, FR-GEO-05).
 *
 * A path is the slugs from province down to the unit, e.g.
 * "koshi/sunsari/namuna/4". When a slug changes, the old path stays as a
 * non-current row so old links can redirect permanently. Called by the
 * importer and data tools after creating or renaming units; refreshes the
 * whole current subtree.
 */
final class RefreshSlugPaths
{
    public function handle(AdminUnit $unit): void
    {
        DB::connection((string) config('tenancy.central_connection'))->transaction(function () use ($unit): void {
            $this->refresh($unit, $this->parentPath($unit));
        });
    }

    private function refresh(AdminUnit $unit, ?string $parentPath): void
    {
        $path = $unit->level === AdminLevel::Country
            ? null
            : ltrim(($parentPath ?? '').'/'.$unit->slug, '/');

        if ($path !== null && $unit->isCurrent()) {
            $this->storeCurrentPath($unit, $path);
        }

        $children = $unit->children()->whereNull('valid_to')->get();

        foreach ($children as $child) {
            $this->refresh($child, $path);
        }
    }

    private function storeCurrentPath(AdminUnit $unit, string $path): void
    {
        $existing = AdminUnitSlug::query()->where('slug_path', $path)->first();

        if ($existing !== null && $existing->admin_unit_id !== $unit->id) {
            throw GeographyException::slugPathTaken($path);
        }

        if ($existing !== null && $existing->is_current) {
            return;
        }

        AdminUnitSlug::query()
            ->where('admin_unit_id', $unit->id)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        if ($existing !== null) {
            $existing->forceFill(['is_current' => true])->save();

            return;
        }

        AdminUnitSlug::query()->create([
            'admin_unit_id' => $unit->id,
            'slug_path' => $path,
            'is_current' => true,
        ]);
    }

    private function parentPath(AdminUnit $unit): ?string
    {
        $slugs = $unit->ancestors()
            ->reject(fn (AdminUnit $ancestor): bool => $ancestor->level === AdminLevel::Country)
            ->map(fn (AdminUnit $ancestor): string => $ancestor->slug)
            ->all();

        return $slugs === [] ? null : implode('/', $slugs);
    }
}

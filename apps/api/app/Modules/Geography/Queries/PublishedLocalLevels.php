<?php

declare(strict_types=1);

namespace App\Modules\Geography\Queries;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * The municipalities a visitor can actually open (FR-GEO-01).
 *
 * Central-only and deliberately narrow: a local level appears here when it is
 * published, current, and has an ACTIVE tenant. A municipality whose data is
 * half-imported, or whose database is in maintenance, is not offered — a picker
 * entry that leads to a 503 is worse than no entry, because the visitor
 * concludes the site is broken rather than that their ward is not ready.
 */
final class PublishedLocalLevels
{
    /**
     * @return Collection<int, array{unit: AdminUnit, wards: int}>
     */
    public function handle(?string $search = null): Collection
    {
        $tenantUnitIds = Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->pluck('admin_unit_id');

        if ($tenantUnitIds->isEmpty()) {
            return collect();
        }

        $localLevels = AdminUnit::query()
            ->publiclyVisible()
            ->where('level', AdminLevel::LocalLevel->value)
            ->whereIn('id', $tenantUnitIds)
            ->with(['parent.parent'])
            ->get();

        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));

            $localLevels = $localLevels->filter(
                fn (AdminUnit $unit): bool => str_contains(mb_strtolower((string) $unit->name_ne), $needle)
                    || str_contains(mb_strtolower((string) $unit->name_en), $needle)
                    || str_contains($unit->slug, $needle),
            );
        }

        $wardCounts = AdminUnit::query()
            ->where('level', AdminLevel::Ward->value)
            ->whereNull('valid_to')
            ->where('is_published', true)
            ->whereIn('parent_id', $localLevels->pluck('id'))
            ->selectRaw('parent_id, count(*) as wards')
            ->groupBy('parent_id')
            ->pluck('wards', 'parent_id');

        return $localLevels
            ->sortBy(fn (AdminUnit $unit): string => (string) $unit->name_en)
            ->values()
            ->map(fn (AdminUnit $unit): array => [
                'unit' => $unit,
                'wards' => (int) ($wardCounts[$unit->id] ?? 0),
            ]);
    }
}

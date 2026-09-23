<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Provenance\Models\SourceType;
use App\Modules\Provenance\Models\TenantSourceType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;

/**
 * Copies national catalogues into a tenant's read-only replicas (docs/12 §4.2).
 *
 * First slice: source_types, so tenant sources can have a foreign key.
 * HW-E29-F02-T02 adds positions, issue categories, the BS calendar and the
 * tenant's own admin_units subtree, and runs this from hw:tenant:create,
 * after each deploy, and whenever central reference data changes.
 */
final readonly class SyncTenantReferenceData
{
    public function __construct(private TenantManager $tenancy) {}

    /**
     * @return int the tenant's new reference_version
     */
    public function handle(Tenant $tenant): int
    {
        $sourceTypes = SourceType::query()->orderBy('authority_rank')->get();

        $this->tenancy->run($tenant, function () use ($sourceTypes): void {
            $now = now();

            foreach ($sourceTypes as $sourceType) {
                TenantSourceType::query()->updateOrCreate(
                    ['key' => $sourceType->key],
                    [
                        'authority_rank' => $sourceType->authority_rank,
                        'label_ne' => $sourceType->label_ne,
                        'label_en' => $sourceType->label_en,
                        'default_provenance_type' => $sourceType->default_provenance_type,
                        'synced_at' => $now,
                    ],
                );
            }

            $keys = $sourceTypes->pluck('key')->all();

            if ($keys !== []) {
                TenantSourceType::query()->whereNotIn('key', $keys)->delete();
            }
        }, allowInactive: true);

        $tenant->forceFill(['reference_version' => $tenant->reference_version + 1])->save();

        return $tenant->reference_version;
    }
}

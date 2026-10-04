<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Actions;

use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Queries\CurrentSeatsQuery;
use App\Modules\Publishing\Models\PublicEntity;
use App\Modules\Publishing\Support\TenantPath;
use App\Modules\Tenancy\TenantManager;
use LogicException;

/**
 * Brings one person's page in one municipality into line with the data
 * (HW-E29-F03-T02).
 *
 * The page exists when PersonController would serve it: the person is
 * published and holds a current seat here. The check is that controller's own
 * query, CurrentSeatsQuery::forPerson(), so the sitemap and the page cannot
 * disagree about whether an address is real.
 *
 * A page that stops existing is unpublished rather than deleted: its row keeps
 * the moment it went, which is what a sitemap consumer needs to drop it.
 *
 * Runs inside the municipality's tenant; the index itself is central.
 */
final class IndexPersonPage
{
    public function __construct(
        private readonly CurrentSeatsQuery $seats,
        private readonly TenantManager $tenancy,
    ) {}

    public function handle(string $personId): ?PublicEntity
    {
        $tenant = $this->tenancy->current() ?? throw new LogicException('IndexPersonPage runs inside a tenant.');

        $person = Person::query()->publiclyVisible()->find($personId);
        $exists = $person !== null && $this->seats->forPerson($personId)->isNotEmpty();

        $entity = PublicEntity::query()
            ->where('entity_type', 'person')
            ->where('entity_id', $personId)
            ->where('tenant_id', $tenant->getKey())
            ->first();

        if (! $exists) {
            if ($entity !== null && $entity->is_published) {
                $entity->forceFill(['is_published' => false, 'lastmod' => now()])->save();
            }

            return $entity;
        }

        $path = 'person/'.TenantPath::of($tenant).'/'.$person->slug;

        $entity ??= new PublicEntity([
            'entity_type' => 'person',
            'entity_id' => $personId,
            'tenant_id' => $tenant->getKey(),
        ]);

        $entity->forceFill([
            'path_ne' => $path,
            'path_en' => $path,
            'title_ne' => $person->full_name_ne,
            'title_en' => $person->full_name_en,
            'is_published' => true,
        ]);

        // lastmod moves only when something a crawler would see has changed.
        if (! $entity->exists || $entity->isDirty()) {
            $entity->forceFill(['lastmod' => now()])->save();
        }

        return $entity;
    }
}

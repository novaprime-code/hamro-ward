<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\ReferenceDataException;
use App\Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * Onboards one local level: central row, database, schema, reference data
 * (HW-E29-F02-T01, docs/12 §6).
 *
 * Four steps that were previously four commands run in the right order by
 * someone who remembered the order. Getting it wrong leaves a tenant that
 * exists but has no database, or a database with no positions catalogue —
 * both of which look fine until a ward page is opened.
 *
 * The tenant stays `provisioning` until every step has succeeded. A failure
 * puts it in `maintenance` rather than deleting it: the database may be
 * half-built, and dropping it automatically would destroy the evidence of why.
 * The operator decides whether to retry or drop.
 */
final class CreateTenant
{
    public function __construct(
        private readonly CreateTenantDatabase $createDatabase,
        private readonly MigrateTenant $migrate,
        private readonly SyncTenantReferenceData $syncReference,
    ) {}

    /**
     * @param  AdminUnit  $localLevel  must be a current, published local level
     */
    public function handle(AdminUnit $localLevel): Tenant
    {
        $this->assertOnboardable($localLevel);

        $tenant = Tenant::query()->create([
            'admin_unit_id' => $localLevel->id,
            'status' => TenantStatus::Provisioning,
        ]);

        try {
            $this->createDatabase->handle($tenant);
            $this->migrate->handle($tenant);
            $this->syncReference->handle($tenant);
        } catch (Throwable $exception) {
            $tenant->forceFill(['status' => TenantStatus::Maintenance])->save();

            throw $exception;
        }

        $tenant->forceFill([
            'status' => TenantStatus::Active,
            'onboarded_at' => now(),
        ])->save();

        return $tenant->refresh();
    }

    /**
     * The database enforces the local-level rule through a trigger, and the
     * unique key on admin_unit_id stops a second tenant for the same place.
     * Checking here too turns both into a readable message instead of a
     * PostgreSQL error, and catches the publication rule the database has no
     * opinion about.
     */
    private function assertOnboardable(AdminUnit $localLevel): void
    {
        if ($localLevel->level !== AdminLevel::LocalLevel) {
            throw ReferenceDataException::misconfiguredTenant(
                $localLevel->slug,
                'a tenant must be a local level, not a '.$localLevel->level->value,
            );
        }

        if (! $localLevel->isCurrent()) {
            throw ReferenceDataException::misconfiguredTenant(
                $localLevel->slug,
                'the local level was closed on '.(string) $localLevel->valid_to?->toDateString(),
            );
        }

        if (! $localLevel->is_published) {
            throw ReferenceDataException::misconfiguredTenant(
                $localLevel->slug,
                'publish the local level and its ancestors first, or its wards will replicate as invisible',
            );
        }

        $existing = Tenant::query()->where('admin_unit_id', $localLevel->id)->first();

        if ($existing !== null) {
            throw ReferenceDataException::misconfiguredTenant(
                $localLevel->slug,
                "it already has tenant {$existing->tenant_key} ({$existing->status->value})",
            );
        }
    }
}

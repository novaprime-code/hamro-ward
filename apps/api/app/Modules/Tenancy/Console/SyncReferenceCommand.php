<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Actions\SyncTenantReferenceData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Pushes central reference data into tenant databases (HW-E29-F02-T02).
 *
 * A deploy step, run after `hw:tenant:migrate`: a new position or a renamed
 * ward is useless until every tenant has it. Safe to run at any time — the sync
 * is a transactional upsert-and-prune, so running it twice changes nothing the
 * second time.
 *
 * One tenant at a time, and a failure does not stop the rest: a single
 * municipality whose geography is mid-edit should not leave the other 752
 * without the new catalogue. The exit code is non-zero if any tenant failed, so
 * CI still notices.
 */
final class SyncReferenceCommand extends Command
{
    protected $signature = 'hw:tenant:sync-reference
                            {--tenant= : Only this tenant key}
                            {--include-inactive : Also sync suspended and maintenance tenants}';

    protected $description = 'Copy central reference data (source types, positions, geography subtree) into tenant databases';

    public function handle(SyncTenantReferenceData $sync): int
    {
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->components->info('No tenants to sync.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $result = $sync->handle($tenant);

                $this->components->twoColumnDetail(
                    $tenant->tenant_key,
                    sprintf(
                        'v%d · %d source types · %d positions · %d units',
                        $result['reference_version'],
                        $result['source_types'],
                        $result['positions'],
                        $result['admin_units'],
                    ),
                );
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error("{$tenant->tenant_key}: {$exception->getMessage()}");
            }
        }

        if ($failed > 0) {
            $this->newLine();
            $this->components->error("{$failed} tenant(s) were not synced.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Tenant> */
    private function tenants(): Collection
    {
        $query = Tenant::query()->orderBy('tenant_key');

        if (($key = $this->option('tenant')) !== null) {
            $query->where('tenant_key', $key);
        }

        if (!$this->option('include-inactive')) {
            $query->where('status', TenantStatus::Active->value);
        }

        return $query->get();
    }
}

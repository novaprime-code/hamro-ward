<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Actions\MigrateTenant;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantSchema;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deploy step 3 (docs/12 §8): migrate tenant databases one at a time.
 *
 * On the first failure the tenant goes into maintenance, the command stops and
 * exits non-zero. Tenants not yet migrated keep serving on the previous,
 * compatible schema (expand/contract rule, NFR-MNT-04).
 */
final class MigrateTenantsCommand extends Command
{
    protected $signature = 'hw:tenant:migrate
        {--tenant=* : Only these tenant keys (repeatable)}';

    protected $description = 'Run tenant migrations for every tenant database, one at a time';

    public function handle(MigrateTenant $migrate): int
    {
        /** @var list<string> $keys */
        $keys = array_values(array_filter((array) $this->option('tenant')));

        $tenants = Tenant::query()
            ->whereIn('status', TenantStatus::migratable())
            ->when($keys !== [], fn ($query) => $query->whereIn('tenant_key', $keys))
            ->orderBy('created_at')
            ->get();

        if ($tenants->isEmpty()) {
            $this->components->info('No tenants to migrate.');

            return self::SUCCESS;
        }

        $expected = TenantSchema::expectedVersion() ?? '(none)';

        foreach ($tenants as $tenant) {
            try {
                $version = $migrate->handle($tenant);

                $this->components->twoColumnDetail(
                    "{$tenant->tenant_key} <fg=gray>{$tenant->database_name}</>",
                    '<fg=green>'.($version ?? 'no migrations').'</>',
                );
            } catch (Throwable $e) {
                $tenant->forceFill(['status' => TenantStatus::Maintenance])->save();
                report($e);

                $this->components->error("Tenant {$tenant->tenant_key} failed and is now in maintenance: {$e->getMessage()}");
                $this->components->warn('Stopped. Remaining tenants were not migrated and keep serving on the previous schema.');

                return self::FAILURE;
            }
        }

        $this->components->info("{$tenants->count()} tenant(s) at {$expected}.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantSchema;
use Illuminate\Console\Command;

final class ListTenantsCommand extends Command
{
    protected $signature = 'hw:tenant:list';

    protected $description = 'Show every municipality, its database, status and schema version';

    public function handle(): int
    {
        $tenants = Tenant::query()->orderBy('created_at')->get();

        if ($tenants->isEmpty()) {
            $this->components->info('No municipalities onboarded yet.');

            return self::SUCCESS;
        }

        $expected = TenantSchema::expectedVersion();

        $names = AdminUnit::query()
            ->whereIn('id', $tenants->pluck('admin_unit_id')->all())
            ->pluck('name_en', 'id');

        $this->table(
            ['Local level', 'Key', 'Database', 'Status', 'Schema', 'Published'],
            $tenants->map(fn (Tenant $tenant): array => [
                (string) ($names[$tenant->admin_unit_id] ?? '—'),
                $tenant->tenant_key,
                $tenant->database_name,
                $tenant->status->value,
                $tenant->schema_version === $expected
                    ? '<fg=green>current</>'
                    : '<fg=yellow>'.($tenant->schema_version ?? 'none').'</>',
                AdminUnit::query()->whereKey($tenant->admin_unit_id)->value('is_published') ? 'yes' : 'no',
            ])->all(),
        );

        $this->components->info("Expected schema version: {$expected}");

        return self::SUCCESS;
    }
}

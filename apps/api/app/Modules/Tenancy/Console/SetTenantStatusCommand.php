<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Suspend a municipality (legal hold, incident) or bring it back.
 * Archiving and deleting stay manual, deliberately.
 */
final class SetTenantStatusCommand extends Command
{
    protected $signature = 'hw:tenant:status
        {tenant : tenant key}
        {status : active|suspended|maintenance}';

    protected $description = 'Change one municipality\'s status';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('tenant_key', (string) $this->argument('tenant'))->first();

        if (!$tenant instanceof Tenant) {
            $this->components->error('No tenant with that key. Try hw:tenant:list.');

            return self::FAILURE;
        }

        $status = TenantStatus::tryFrom((string) $this->argument('status'));

        if ($status === null || !in_array($status, [TenantStatus::Active, TenantStatus::Suspended, TenantStatus::Maintenance], true)) {
            $this->components->error('Status must be active, suspended or maintenance.');

            return self::FAILURE;
        }

        $previous = $tenant->status;
        $tenant->forceFill(['status' => $status])->save();

        $this->components->info("{$tenant->tenant_key}: {$previous->value} → {$status->value}");

        return self::SUCCESS;
    }
}

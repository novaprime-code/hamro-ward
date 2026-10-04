<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Console;

use App\Modules\Publishing\Jobs\DispatchTenantOutbox;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * `hw:outbox:dispatch` — queues an outbox drain for every active municipality.
 * Scheduled every minute as the safety net behind the drain each import
 * queues for itself.
 */
final class DispatchOutboxCommand extends Command
{
    protected $signature = 'hw:outbox:dispatch';

    protected $description = 'Queue an outbox drain for every active municipality';

    public function handle(): int
    {
        $count = 0;

        foreach (Tenant::query()->where('status', TenantStatus::Active->value)->pluck('id') as $tenantId) {
            DispatchTenantOutbox::dispatch((string) $tenantId);
            $count++;
        }

        $this->components->info("Queued {$count} outbox drain(s).");

        return self::SUCCESS;
    }
}

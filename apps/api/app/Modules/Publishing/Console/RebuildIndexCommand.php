<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Console;

use App\Modules\Publishing\Actions\RebuildPersonPages;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * `hw:index:rebuild` — recomputes every municipality's person pages in the
 * central index.
 *
 * Needed for the changes that decide whether a page exists but are not tenant
 * changes, so no outbox event announces them: a person published centrally, a
 * ward published after onboarding. Scheduled hourly, and the command to run
 * after a restore. Visiting each tenant in turn is fine here — it is the
 * request path that must never do that.
 */
final class RebuildIndexCommand extends Command
{
    protected $signature = 'hw:index:rebuild';

    protected $description = 'Recompute the central index of person pages for every active municipality';

    public function handle(TenantManager $tenancy, RebuildPersonPages $rebuild): int
    {
        foreach (Tenant::query()->where('status', TenantStatus::Active->value)->get() as $tenant) {
            $pages = $tenancy->run($tenant, fn (): int => $rebuild->handle());
            $this->components->twoColumnDetail($tenant->tenant_key, "{$pages} person page(s)");
        }

        return self::SUCCESS;
    }
}

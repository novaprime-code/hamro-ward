<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Console\CreateTenantCommand;
use App\Modules\Tenancy\Console\SyncReferenceCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the tenancy commands added after the foundation bundle:
 * hw:tenant:create (HW-E29-F02-T01) and hw:tenant:sync-reference
 * (HW-E29-F02-T02).
 *
 * A separate provider rather than an edit to TenancyServiceProvider, so this
 * bundle adds a complete file instead of asking for two lines to be inserted
 * into an existing one. Registering a command twice is harmless — Artisan
 * keys them by signature — so these can later be folded into the main
 * provider whenever that file is next touched.
 */
final class TenancyConsoleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateTenantCommand::class,
                SyncReferenceCommand::class,
            ]);
        }
    }
}

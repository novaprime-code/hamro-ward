<?php

declare(strict_types=1);

namespace App\Modules\Imports\Providers;

use App\Modules\Imports\Console\ImportCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers `hw:import`. The importer has no routes: loading data is an
 * operator action from the CLI, reviewed before it runs, never a web request.
 */
final class ImportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImportCommand::class]);
        }
    }
}

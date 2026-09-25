<?php

declare(strict_types=1);

namespace App\Modules\Demo\Providers;

use App\Modules\Demo\Console\DropDemoCommand;
use App\Modules\Demo\Console\SeedDemoCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the demonstration commands.
 *
 * The whole module is designed to be deletable: remove this provider from
 * bootstrap/providers.php, delete app/Modules/Demo and database/seeders/
 * DemoDataSeeder.php, and nothing else in the application refers to it. That
 * property is the point — demonstration code that has grown roots into the
 * product is demonstration code that ships.
 */
final class DemoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedDemoCommand::class,
                DropDemoCommand::class,
            ]);
        }
    }
}

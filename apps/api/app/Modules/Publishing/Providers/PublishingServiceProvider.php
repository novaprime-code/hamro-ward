<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Providers;

use App\Modules\Publishing\Console\DispatchOutboxCommand;
use App\Modules\Publishing\Console\RebuildIndexCommand;
use Illuminate\Support\ServiceProvider;

final class PublishingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchOutboxCommand::class,
                RebuildIndexCommand::class,
            ]);
        }
    }
}

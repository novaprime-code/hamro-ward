<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Console\MigrateTenantsCommand;
use App\Modules\Tenancy\Queue\JobTenancy;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
        $this->app->singleton(JobTenancy::class);
    }

    public function boot(): void
    {
        // Central migrations live in database/migrations/central; tenant
        // migrations only run through hw:tenant:migrate with an explicit path.
        $this->loadMigrationsFrom(database_path('migrations/central'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateTenantsCommand::class,
            ]);
        }

        // Jobs remember the tenant they were dispatched in…
        Queue::createPayloadUsing(
            fn (): array => $this->app->make(JobTenancy::class)->payload(),
        );

        // …and run inside it again, restoring the caller's context afterwards.
        Event::listen(
            JobProcessing::class,
            fn (JobProcessing $event) => $this->app->make(JobTenancy::class)->begin($event),
        );

        Event::listen(
            [JobProcessed::class, JobExceptionOccurred::class],
            fn () => $this->app->make(JobTenancy::class)->finish(),
        );
    }
}

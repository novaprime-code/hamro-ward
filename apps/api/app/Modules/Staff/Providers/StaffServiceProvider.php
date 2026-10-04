<?php

declare(strict_types=1);

namespace App\Modules\Staff\Providers;

use App\Modules\Staff\Console\CreateStaffCommand;
use App\Modules\Staff\Policies\StaffTenantPolicy;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class StaffServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Tenant::class, StaffTenantPolicy::class);

        /*
         * Per address, across every sign-in step. The per-ACCOUNT lockout
         * (five failures, fifteen minutes) is in StaffAuthController; this
         * stops one address from spraying many accounts.
         */
        RateLimiter::for('staff-sign-in', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([CreateStaffCommand::class]);
        }
    }
}

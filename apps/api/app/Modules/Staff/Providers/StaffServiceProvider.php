<?php

declare(strict_types=1);

namespace App\Modules\Staff\Providers;

use App\Modules\Staff\Policies\StaffTenantPolicy;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class StaffServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Tenant::class, StaffTenantPolicy::class);
    }
}

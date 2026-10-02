<?php

declare(strict_types=1);
use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Demo\Providers\DemoServiceProvider;
use App\Modules\Offices\Providers\OfficesServiceProvider;
use App\Modules\Provenance\Providers\ProvenanceServiceProvider;
use App\Modules\Staff\Providers\StaffServiceProvider;
use App\Modules\Support\Providers\SecurityServiceProvider;
use App\Modules\Tenancy\Providers\TenancyConsoleServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

/*
| Order matters twice here:
|  - TenancyServiceProvider binds the tenant connections everything else uses.
|  - ProvenanceServiceProvider starts the morph map; OfficesServiceProvider
|    and StaffServiceProvider merge into it, so both must come after it —
|    enforceMorphMap REPLACES the map, so an earlier entry would be dropped.
|
| SecurityServiceProvider defines the named rate limiters the API routes refer
| to, so it has to be registered before routing resolves 'throttle:public-read'.
|
| DemoServiceProvider is last and registers nothing but two console commands.
| Removing that line and deleting app/Modules/Demo retires the demonstration
| completely (docs/13 §6).
*/
return [
    AppServiceProvider::class,
    SecurityServiceProvider::class,
    AuthServiceProvider::class,
    TenancyServiceProvider::class,
    TenancyConsoleServiceProvider::class,
    ProvenanceServiceProvider::class,
    OfficesServiceProvider::class,
    StaffServiceProvider::class,
    DemoServiceProvider::class,
];

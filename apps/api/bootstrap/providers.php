<?php

declare(strict_types=1);
use App\Modules\Accounts\Providers\AccountsServiceProvider;
use App\Modules\Demo\Providers\DemoServiceProvider;
use App\Modules\Imports\Providers\ImportsServiceProvider;
use App\Modules\Offices\Providers\OfficesServiceProvider;
use App\Modules\Provenance\Providers\ProvenanceServiceProvider;
use App\Modules\Publishing\Providers\PublishingServiceProvider;
use App\Modules\Staff\Providers\StaffServiceProvider;
use App\Modules\Support\Providers\SecurityServiceProvider;
use App\Modules\Tenancy\Providers\TenancyConsoleServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

/*
| Order matters twice here:
|  - TenancyServiceProvider binds the tenant connections everything else uses.
|  - ProvenanceServiceProvider starts the morph map; OfficesServiceProvider
|    merges its subject types into it.
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
    TenancyServiceProvider::class,
    TenancyConsoleServiceProvider::class,
    ProvenanceServiceProvider::class,
    OfficesServiceProvider::class,
    ImportsServiceProvider::class,
    PublishingServiceProvider::class,
    StaffServiceProvider::class,
    AccountsServiceProvider::class,
    DemoServiceProvider::class,
];

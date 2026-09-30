<?php

declare(strict_types=1);

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
    App\Providers\AppServiceProvider::class,
    App\Modules\Support\Providers\SecurityServiceProvider::class,
    App\Modules\Tenancy\Providers\TenancyServiceProvider::class,
    App\Modules\Tenancy\Providers\TenancyConsoleServiceProvider::class,
    App\Modules\Provenance\Providers\ProvenanceServiceProvider::class,
    App\Modules\Offices\Providers\OfficesServiceProvider::class,
    App\Modules\Demo\Providers\DemoServiceProvider::class,
];

<?php

declare(strict_types=1);

/*
| Order matters twice here:
|  - TenancyServiceProvider binds the tenant connections everything else uses.
|  - ProvenanceServiceProvider starts the morph map; OfficesServiceProvider
|    merges its subject types into it.
*/
return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Tenancy\Providers\TenancyServiceProvider::class,
    App\Modules\Provenance\Providers\ProvenanceServiceProvider::class,
    App\Modules\Offices\Providers\OfficesServiceProvider::class,
];

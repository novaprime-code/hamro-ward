<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Tenancy\Providers\TenancyServiceProvider::class,
    App\Modules\Provenance\Providers\ProvenanceServiceProvider::class,
];

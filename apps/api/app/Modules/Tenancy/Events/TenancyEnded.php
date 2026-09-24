<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use App\Modules\Tenancy\Models\Tenant;

final readonly class TenancyEnded
{
    public function __construct(public Tenant $tenant) {}
}

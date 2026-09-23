<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models\Concerns;

/**
 * For models whose table lives in the central database (docs/12 §3).
 * Every model under app/Modules/{Module}/Models uses exactly one of the two
 * connection traits — enforced by tests/Arch/ModelConnectionTest.php.
 */
trait UsesCentralConnection
{
    public function getConnectionName(): string
    {
        return (string) config('tenancy.central_connection', 'central');
    }
}

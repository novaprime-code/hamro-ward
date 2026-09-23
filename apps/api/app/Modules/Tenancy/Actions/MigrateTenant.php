<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantSchema;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs database/migrations/tenant against one tenant database as the schema
 * owner and records the applied schema_version on the central tenant row.
 */
final readonly class MigrateTenant
{
    public function __construct(private TenantManager $tenancy) {}

    /**
     * @return string|null the schema version now applied
     */
    public function handle(Tenant $tenant): ?string
    {
        $connection = (string) config('tenancy.tenant_owner_connection');

        $version = $this->tenancy->run($tenant, function () use ($tenant, $connection): ?string {
            $exitCode = Artisan::call('migrate', [
                '--database' => $connection,
                '--path' => (string) config('tenancy.migrations_path'),
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw TenancyException::migrationFailed($tenant, trim(Artisan::output()));
            }

            return TenantSchema::appliedVersion($connection);
        }, allowInactive: true);

        $tenant->forceFill(['schema_version' => $version])->save();

        return $version;
    }
}

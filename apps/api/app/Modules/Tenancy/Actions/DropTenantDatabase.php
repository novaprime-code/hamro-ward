<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Pg;
use App\Modules\Tenancy\Support\TenantDatabaseName;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Drops a tenant database. Outside local/testing the tenant must be archived
 * first (hw:tenant:archive, docs/12 §5) — there is no other path to deletion.
 */
final readonly class DropTenantDatabase
{
    public function __construct(private TenantManager $tenancy) {}

    public function handle(Tenant $tenant): void
    {
        if (! app()->environment(['local', 'testing']) && $tenant->status !== TenantStatus::Archived) {
            throw TenancyException::dropNotAllowed($tenant);
        }

        $this->force($tenant);
    }

    /**
     * Internal: also used to clean up after a failed CreateTenantDatabase.
     */
    public function force(Tenant $tenant): void
    {
        $name = $tenant->database_name;
        TenantDatabaseName::assertValid($name);

        if ($this->tenancy->current()?->is($tenant) === true) {
            $this->tenancy->end();
        }

        DB::purge((string) config('tenancy.tenant_connection'));
        DB::purge((string) config('tenancy.tenant_owner_connection'));

        DB::connection((string) config('tenancy.provisioner_connection'))
            ->statement('DROP DATABASE IF EXISTS '.Pg::ident($name).' WITH (FORCE)');
    }
}

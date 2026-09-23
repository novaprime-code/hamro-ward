<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Pg;
use App\Modules\Tenancy\Support\TenantDatabaseName;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates the PostgreSQL database for a tenant and grants the application
 * and backup roles (docs/12 §5, §7). CLI only: the provisioner connection's
 * credentials are not available to web containers in production.
 *
 * Used by hw:tenant:create (HW-E29-F02-T01) and tests.
 */
final readonly class CreateTenantDatabase
{
    public function __construct(
        private TenantManager $tenancy,
        private DropTenantDatabase $drop,
    ) {}

    public function handle(Tenant $tenant): void
    {
        $name = $tenant->database_name;
        TenantDatabaseName::assertValid($name);

        $provisioner = DB::connection((string) config('tenancy.provisioner_connection'));

        $exists = $provisioner->selectOne('select 1 as found from pg_database where datname = ?', [$name]);

        if ($exists !== null) {
            throw TenancyException::databaseAlreadyExists($name);
        }

        // CREATE DATABASE cannot run inside a transaction; the provisioner
        // connection is never used transactionally.
        $provisioner->statement(sprintf(
            'CREATE DATABASE %s OWNER %s TEMPLATE template1',
            Pg::ident($name),
            Pg::ident((string) config('tenancy.roles.owner')),
        ));

        try {
            $this->tenancy->run(
                $tenant,
                fn () => $this->prepare(
                    DB::connection((string) config('tenancy.tenant_owner_connection')),
                    $name,
                ),
                allowInactive: true,
            );
        } catch (Throwable $e) {
            // Never leave a half-prepared database behind.
            $this->drop->force($tenant);

            throw $e;
        }
    }

    private function prepare(Connection $db, string $name): void
    {
        /** @var list<string> $extensions */
        $extensions = (array) config('tenancy.extensions', []);

        foreach ($extensions as $extension) {
            $db->statement('CREATE EXTENSION IF NOT EXISTS '.Pg::ident($extension));
        }

        $database = Pg::ident($name);

        $app = (string) config('tenancy.roles.app');

        if ($app !== '') {
            $role = Pg::ident($app);
            $db->statement("GRANT CONNECT ON DATABASE {$database} TO {$role}");
            $db->statement("GRANT USAGE ON SCHEMA public TO {$role}");
            $db->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
            $db->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
        }

        $backup = (string) config('tenancy.roles.backup');

        if ($backup !== '') {
            $role = Pg::ident($backup);
            $db->statement("GRANT CONNECT ON DATABASE {$database} TO {$role}");
            $db->statement("GRANT USAGE ON SCHEMA public TO {$role}");
            $db->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO {$role}");
        }
    }
}

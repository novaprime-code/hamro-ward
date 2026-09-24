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
 * Creates the PostgreSQL database for a tenant and grants the application role
 * (docs/12 §5, §7, D-014).
 *
 * The database is copied from the template (`tenancy.template_database`), which
 * already has the extensions and default privileges, so the provisioner role
 * needs nothing beyond CREATEDB. Public access is revoked immediately: the
 * server is shared with other applications.
 *
 * CLI only — the provisioner connection's credentials are not present in the
 * web containers in production.
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
            'CREATE DATABASE %s OWNER %s TEMPLATE %s',
            Pg::ident($name),
            Pg::ident((string) config('tenancy.roles.owner')),
            Pg::ident($this->templateDatabase()),
        ));

        try {
            $provisioner->statement('REVOKE ALL ON DATABASE '.Pg::ident($name).' FROM PUBLIC');

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
        // Only when no template is configured; the template already has them.
        if ($this->templateDatabase() === 'template1') {
            /** @var list<string> $extensions */
            $extensions = (array) config('tenancy.extensions', []);

            foreach ($extensions as $extension) {
                $db->statement('CREATE EXTENSION IF NOT EXISTS '.Pg::ident($extension));
            }
        }

        $database = Pg::ident($name);

        // Database-level grants are not copied from the template, so they are
        // always applied here.
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

    private function templateDatabase(): string
    {
        $template = trim((string) config('tenancy.template_database'));

        return $template === '' ? 'template1' : $template;
    }
}

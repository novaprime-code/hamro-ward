<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenancy (D-010, D-013, D-014, docs/12)
|--------------------------------------------------------------------------
| One PostgreSQL database per onboarded local level, plus the central database,
| on a shared PostgreSQL server that also hosts other applications.
| Connection names refer to config/database.php.
*/

return [

    // Identity, geography, registries, cross-tenant indexes.
    'central_connection' => 'central',

    // Runtime connection used by the application for tenant data (hw_app in production).
    'tenant_connection' => 'tenant',

    // Runtime connection used by migrations and grants (hw_owner). CLI only.
    'tenant_owner_connection' => 'tenant_owner',

    // CREATEDB role; used only by the CLI to create and drop tenant databases.
    'provisioner_connection' => 'provisioner',

    // Tenant databases are named <prefix><8 random hex chars>, e.g. hw_t_5f3a9c1e.
    // The prefix also keeps them apart from other applications on the shared server.
    'database_prefix' => env('TENANT_DB_PREFIX', 'hw_t_'),

    /*
    | Every tenant database is copied from this template, which already carries
    | PostGIS, pg_trgm, btree_gist, citext and the default privileges for the
    | application role. Because the template is marked datistemplate, a plain
    | CREATEDB role can copy it — no superuser rights, and template1 stays
    | untouched for the other applications on the server (D-014).
    |
    | Set to an empty string to fall back to creating extensions per database,
    | which then requires a superuser.
    */
    'template_database' => env('TENANT_DB_TEMPLATE', 'template_hamroward'),

    // Relative to the application base path.
    'migrations_path' => 'database/migrations/tenant',

    // PostgreSQL roles granted on every new tenant database (docs/12 §7).
    // Leave a role empty to skip its grants.
    'roles' => [
        'owner' => env('TENANT_DB_OWNER_ROLE', 'hw_owner'),
        'app' => env('TENANT_DB_APP_ROLE', 'hw_app'),
        'backup' => env('TENANT_DB_BACKUP_ROLE', ''),
    ],

    // Created per database only when no template is configured.
    'extensions' => ['postgis', 'pg_trgm', 'btree_gist'],

];

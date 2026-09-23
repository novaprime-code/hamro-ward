<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenancy (D-010, D-013, docs/12)
|--------------------------------------------------------------------------
| One PostgreSQL database per onboarded local level, plus the central database.
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
    'database_prefix' => env('TENANT_DB_PREFIX', 'hw_t_'),

    // Relative to the application base path.
    'migrations_path' => 'database/migrations/tenant',

    // PostgreSQL roles granted on every new tenant database (docs/12 §7).
    // Set app/backup to an empty string to skip their grants (for example in CI).
    'roles' => [
        'owner' => env('TENANT_DB_OWNER_ROLE', 'hw_owner'),
        'app' => env('TENANT_DB_APP_ROLE', 'hw_app'),
        'backup' => env('TENANT_DB_BACKUP_ROLE', 'hw_backup'),
    ],

    // Created if missing. Pre-installing them in template1 means a non-superuser
    // provisioner never needs to create PostGIS itself.
    'extensions' => ['postgis', 'pg_trgm', 'btree_gist'],

];

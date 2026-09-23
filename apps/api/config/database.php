<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    | "central" holds identity, geography, persons, parties, registries and the
    | cross-tenant indexes (docs/12 §3). Tenant models use the "tenant"
    | connection, whose database is set at runtime by TenantManager.
    */

    'default' => env('DB_CONNECTION', 'central'),

    'connections' => [

        'central' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'hw_central'),
            'username' => env('DB_USERNAME', 'hw_owner'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        | Tenant data, application role (hw_app in production). "database" stays
        | null until TenantManager initializes a tenant; tenant models refuse to
        | resolve a connection before that (UsesTenantConnection).
        */
        'tenant' => [
            'driver' => 'pgsql',
            'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_DB_PORT', env('DB_PORT', '5432')),
            'database' => null,
            'username' => env('TENANT_DB_USERNAME', 'hw_app'),
            'password' => env('TENANT_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        | Tenant schema owner (hw_owner). Used by tenant migrations, reference
        | sync and grants — CLI and deploy only. Database set at runtime.
        */
        'tenant_owner' => [
            'driver' => 'pgsql',
            'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_DB_PORT', env('DB_PORT', '5432')),
            'database' => null,
            'username' => env('TENANT_OWNER_DB_USERNAME', 'hw_owner'),
            'password' => env('TENANT_OWNER_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        | CREATEDB role for creating and dropping tenant databases. Its
        | credentials must not be present in web containers in production
        | (docs/12 §7).
        */
        'provisioner' => [
            'driver' => 'pgsql',
            'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_DB_PORT', env('DB_PORT', '5432')),
            'database' => 'postgres',
            'username' => env('TENANT_PROVISIONER_USERNAME', 'hw_provisioner'),
            'password' => env('TENANT_PROVISIONER_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'hamro-ward'), '_').'_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

];

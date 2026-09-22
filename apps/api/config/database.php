<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    | "central" holds identity, geography, persons, parties, registries and the
    | cross-tenant indexes (docs/12 §3). Tenant models switch to the "tenant"
    | connection, whose database name is set at runtime by the tenancy layer.
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
        | Template for tenant databases. "database" stays null until a request or
        | job initializes a tenant (HW-E29-F01-T02 / HW-E29-F03-T01); resolving a
        | tenant model before that must fail loudly rather than silently read
        | central data.
        */
        'tenant' => [
            'driver' => 'pgsql',
            'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_DB_PORT', env('DB_PORT', '5432')),
            'database' => null,
            'username' => env('TENANT_DB_USERNAME', 'hw_owner'),
            'password' => env('TENANT_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        | Used only by the CLI command that creates tenant databases. The web and
        | worker containers must not receive these credentials in production
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

    /*
    |--------------------------------------------------------------------------
    | Tenant database naming
    |--------------------------------------------------------------------------
    | hw_t_<tenant_key>, where tenant_key is derived from the local level's UUID,
    | never from its name, so a renamed municipality needs no database rename.
    */

    'tenant_prefix' => env('TENANT_DB_PREFIX', 'hw_t_'),

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'hamro-ward'), '_').'_database_'),
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

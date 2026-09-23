<?php

declare(strict_types=1);

use App\Modules\Tenancy\Actions\CreateTenantDatabase;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Actions\MigrateTenant;
use App\Modules\Tenancy\Actions\SyncTenantReferenceData;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Feature and Unit tests boot the Laravel application.
| Arch tests run without it (tests/Arch).
*/
uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Runs a statement the database must reject. The savepoint keeps the test's
 * outer transaction usable afterwards (PostgreSQL aborts it otherwise).
 */
function expectRejectedByDatabase(Closure $statement): void
{
    expect(fn () => DB::connection('central')->transaction($statement))
        ->toThrow(QueryException::class);
}

/**
 * Creates and migrates a throwaway tenant database, syncs its reference data,
 * runs the test, then drops the database.
 *
 * @param  Closure(Tenant): void  $test
 */
function withTenantDatabase(Closure $test, ?Tenant $tenant = null): void
{
    $tenant ??= Tenant::factory()->create();

    try {
        app(CreateTenantDatabase::class)->handle($tenant);
        app(MigrateTenant::class)->handle($tenant);
        app(SyncTenantReferenceData::class)->handle($tenant);

        $test($tenant->refresh());
    } finally {
        app(DropTenantDatabase::class)->handle($tenant);
    }
}

<?php

declare(strict_types=1);

use App\Modules\Geography\Actions\RefreshSlugPaths;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Tenancy\Actions\CreateTenantDatabase;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Actions\MigrateTenant;
use App\Modules\Tenancy\Actions\SyncTenantReferenceData;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Pest\Support\HigherOrderTapProxy;
use Tests\TestCase;

/*
| Feature and Unit tests boot the Laravel application.
| Arch tests run without it (tests/Arch).
*/
uses(TestCase::class)->in('Feature', 'Unit');

/**
 * The running test case, for helper functions that live outside a test
 * closure and so have no $this. Pest's test() with no arguments wraps it in a
 * proxy and is declared to return a pending call as well, so analysis cannot
 * see getJson() or artisan() through it; this unwraps it and says so.
 */
function testCase(): TestCase
{
    $proxy = test();

    if (! $proxy instanceof HigherOrderTapProxy || ! $proxy->target instanceof TestCase) {
        throw new LogicException('testCase() is only available while a Feature or Unit test is running.');
    }

    return $proxy->target;
}

/**
 * Runs a statement the database must reject. The savepoint keeps the test's
 * outer transaction usable afterwards (PostgreSQL aborts it otherwise).
 */
/** @param  Closure(): mixed  $statement */
function expectRejectedByDatabase(Closure $statement): void
{
    expect(fn () => DB::connection('central')->transaction($statement))
        ->toThrow(QueryException::class);
}

/**
 * The same, inside the currently initialized tenant. A tenant database has its
 * own constraints — the seat exclusion, the seat-reference trigger — and they
 * need proving separately from the central ones.
 *
 * @param  Closure(): mixed  $statement
 */
function expectRejectedByTenantDatabase(Closure $statement): void
{
    expect(fn () => DB::connection((string) config('tenancy.tenant_connection'))->transaction($statement))
        ->toThrow(QueryException::class);
}

/**
 * The initialized tenant's first ward and its local level — the two
 * constituencies every ward page shows side by side (docs/02 §4.1).
 *
 * @return array{0: string, 1: string} ward id, local level id
 */
function tenantSeatContext(): array
{
    $localLevel = TenantAdminUnit::query()
        ->where('level', AdminLevel::LocalLevel->value)
        ->firstOrFail();

    $ward = TenantAdminUnit::query()
        ->where('level', AdminLevel::Ward->value)
        ->orderBy('ward_number')
        ->firstOrFail();

    return [$ward->id, $localLevel->id];
}

/**
 * A tenant on a published local level with published wards — the state a real
 * municipality is in once it goes live, and the only state in which
 * v_current_seats generates anything.
 *
 * Returns the tenant; its local level and wards are on the central connection.
 */
function tenantWithPublishedWards(
    int $wards = 2,
    LocalLevelType $type = LocalLevelType::SubMetropolitanCity,
    int $unpublishedWards = 0,
): Tenant {
    $localLevel = AdminUnit::factory()->localLevel($type)->published()->create();

    // range(1, 0) counts backwards in PHP, so count with for instead.
    for ($number = 1; $number <= $wards; $number++) {
        AdminUnit::factory()->ward($number)->childOf($localLevel)->published()->create();
    }

    for ($offset = 1; $offset <= $unpublishedWards; $offset++) {
        AdminUnit::factory()->ward($wards + $offset)->childOf($localLevel)->create();
    }

    /*
     * Real onboarding cannot reach a unit that has no slug path — the command
     * resolves the local level BY its path, and RefreshSlugPaths walks the
     * whole current subtree, so the wards always have one too. Building the
     * fixture without this left admin_unit_slugs empty, the sync had nothing
     * to copy, and a tenant came up with null slug_paths: no ward URLs at
     * all, in the fixture every seat test runs on.
     */
    app(RefreshSlugPaths::class)->handle($localLevel->refresh());

    return Tenant::factory()->forLocalLevel($localLevel)->create();
}

/**
 * A municipality part-way through onboarding: ward 1 is published, ward 2 is
 * still being checked. Nothing about ward 2 may reach a public page.
 */
function tenantWithOneUnpublishedWard(): Tenant
{
    return tenantWithPublishedWards(wards: 1, unpublishedWards: 1);
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

<?php

declare(strict_types=1);

use App\Modules\Geography\Actions\PublishAdminUnit;
use App\Modules\Geography\Actions\RefreshSlugPaths;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Imports\Support\ImportFile;
use App\Modules\Tenancy\Actions\CreateTenantDatabase;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Actions\MigrateTenant;
use App\Modules\Tenancy\Actions\SyncTenantReferenceData;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

/*
| Import sheets (hw:import). Shared by the importer's own tests and by the
| tenant isolation suite, which builds its two municipalities through the
| importer so that real, verified evidence sits on both sides of the boundary.
|
| Sheets are written to a fresh temporary directory each, removed when the
| process ends.
*/

/**
 * Writes a sheet to a fresh directory. Rows are given by column name; the
 * header is always the exact one from docs/05 §13, in its order.
 *
 * @param  array<string, list<array<string, string>>>  $files
 */
function sheet(array $files): string
{
    $directory = sys_get_temp_dir().'/hw-import-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);
    register_shutdown_function(static fn () => removeSheet($directory));

    foreach ($files as $name => $rows) {
        $columns = ImportFile::from($name)->columns();
        $handle = fopen($directory.'/'.$name, 'w');
        fputcsv($handle, $columns, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column): string => $row[$column] ?? '', $columns), escape: '');
        }

        fclose($handle);
    }

    return $directory;
}

/**
 * Deletes a sheet directory at process exit. Plain PHP on purpose: shutdown
 * functions run after the application is torn down, when a facade has no
 * container left to resolve from — and a fatal error there fails the whole
 * run with exit code 255 after every test has passed.
 */
function removeSheet(string $directory): void
{
    foreach (glob($directory.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($directory);
}

/** @param  array<string, mixed>  $options */
function runImport(string $directory, array $options = []): int
{
    $report = $directory.'/report.md';

    return testCase()->artisan('hw:import', ['directory' => $directory, '--force' => true, '--report' => $report, ...$options])
        ->run();
}

function lastReport(string $directory): string
{
    return (string) file_get_contents($directory.'/report.md');
}

/** The tenant's local level as a slug path, e.g. pradesh-x/jilla-y/nagar-z. */
function tenantPath(Tenant $tenant): string
{
    return (string) AdminUnitSlug::query()
        ->where('admin_unit_id', $tenant->admin_unit_id)
        ->where('is_current', true)
        ->value('slug_path');
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantManager::class)->run($tenant, $callback, allowInactive: true);
}

/**
 * Publishes the country, province and district above a tenant's municipality.
 * The factory publishes the municipality and its wards but not what sits above
 * them, and the public read path hides anything under an unpublished
 * ancestor — so without this, every request about the tenant is a 404.
 */
function publishAncestorsOf(Tenant $tenant): void
{
    foreach (AdminUnit::query()->findOrFail($tenant->admin_unit_id)->ancestors() as $ancestor) {
        if (! $ancestor->is_published) {
            app(PublishAdminUnit::class)->publish($ancestor);
        }
    }
}

<?php

declare(strict_types=1);

use App\Modules\Tenancy\Actions\CreateTenantDatabase;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\Support\TenantSchema;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\WriteTenantProbeJob;

/*
| These tests create and drop real PostgreSQL databases. They need the roles
| from infra/docker/postgres/init/00-roles.sql (hw_provisioner with CREATEDB,
| member of hw_owner) — present locally after `make up` and in CI.
*/

uses(RefreshDatabase::class);

it('creates, migrates and records the schema version of a tenant database', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        expect($tenant->schema_version)->toBe(TenantSchema::expectedVersion());

        $tenantMigrations = app(TenantManager::class)->run(
            $tenant,
            fn (): array => DB::connection('tenant')->table('migrations')->pluck('migration')->all(),
        );

        $centralMigrations = DB::connection('central')->table('migrations')->pluck('migration')->all();

        expect($tenantMigrations)->toContain($tenant->schema_version)
            ->and($centralMigrations)->not->toContain($tenant->schema_version);
    });
});

it('keeps each tenant\'s data in its own database', function (): void {
    withTenantDatabase(function (Tenant $first): void {
        withTenantDatabase(function (Tenant $second) use ($first): void {
            $tenancy = app(TenantManager::class);

            $tenancy->run($first, function (): void {
                TenantSetting::query()->create([
                    'key' => 'issues.reporting_scope',
                    'value' => 'saved_wards_only',
                    'reason' => 'isolation test',
                ]);
            });

            $inFirst = $tenancy->run(
                $first,
                fn (): mixed => TenantSetting::query()->find('issues.reporting_scope')?->value,
            );

            $inSecond = $tenancy->run(
                $second,
                fn (): mixed => TenantSetting::query()->find('issues.reporting_scope')?->value,
            );

            expect($inFirst)->toBe('saved_wards_only')
                ->and($inSecond)->toBeNull();
        });
    });
});

it('runs a queued job inside the tenant it was dispatched from', function (): void {
    config(['queue.default' => 'database']);

    withTenantDatabase(function (Tenant $tenant): void {
        $tenancy = app(TenantManager::class);

        // A statement body, not an arrow function: PendingDispatch dispatches on
        // destruction, which must happen while the tenant is still active.
        $tenancy->run($tenant, function (): void {
            WriteTenantProbeJob::dispatch('namaste');
        });

        expect($tenancy->initialized())->toBeFalse();

        $this->artisan('queue:work', ['--once' => true, '--queue' => 'default'])
            ->assertSuccessful()
            ->run();

        $value = $tenancy->run(
            $tenant,
            fn (): mixed => DB::connection('tenant')->table('settings')->where('key', 'probe')->value('value'),
        );

        expect(json_decode((string) $value, true))->toBe(['message' => 'namaste'])
            ->and($tenancy->initialized())->toBeFalse();
    });
});

it('refuses to create a database that already exists', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        expect(fn () => app(CreateTenantDatabase::class)->handle($tenant))
            ->toThrow(TenancyException::class);
    });
});

it('puts a tenant into maintenance and stops when its migration fails', function (): void {
    // A tenant row without a database: migrating it must fail.
    $broken = Tenant::factory()->create();

    $this->artisan('hw:tenant:migrate')->assertFailed()->run();

    expect($broken->refresh()->status)->toBe(TenantStatus::Maintenance);
});

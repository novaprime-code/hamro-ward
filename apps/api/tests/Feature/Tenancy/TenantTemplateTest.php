<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
| D-014: municipality databases are copied from template_hamroward, so they
| arrive with PostGIS and the application role's privileges already in place and
| the provisioner never needs superuser rights.
*/

it('copies extensions from the template into every municipality database', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $extensions = app(TenantManager::class)->run(
            $tenant,
            fn (): array => DB::connection('tenant')->table('pg_extension')->pluck('extname')->all(),
        );

        expect($extensions)->toContain('postgis')
            ->and($extensions)->toContain('pg_trgm')
            ->and($extensions)->toContain('btree_gist');
    });
});

it('can store and query a location without extra setup', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $metres = app(TenantManager::class)->run($tenant, function (): float {
            $row = DB::connection('tenant')->selectOne(<<<'SQL'
                SELECT ST_Distance(
                    'SRID=4326;POINT(87.2718 26.6646)'::geography,
                    'SRID=4326;POINT(87.2818 26.6646)'::geography
                ) AS metres
                SQL);

            return (float) ($row->metres ?? 0);
        });

        // Roughly a kilometre apart at this latitude
        expect($metres)->toBeGreaterThan(900.0)->toBeLessThan(1100.0);
    });
});

it('keeps new municipality databases closed to other roles', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $acl = DB::connection('provisioner')->selectOne(
            'select datacl::text as acl from pg_database where datname = ?',
            [$tenant->database_name],
        );

        expect((string) ($acl->acl ?? ''))->not->toContain('=Tc/')   // no PUBLIC grant
            ->and((string) ($acl->acl ?? ''))->toContain('hw_app');
    });
});

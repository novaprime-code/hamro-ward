<?php

declare(strict_types=1);

use App\Modules\Provenance\Enums\ProvenanceType;
use App\Modules\Provenance\Enums\SourceScope;
use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Models\Source;
use App\Modules\Provenance\Models\SourceLink;
use App\Modules\Provenance\Models\TenantSource;
use App\Modules\Provenance\Models\TenantSourceLink;
use App\Modules\Provenance\Models\TenantSourceType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
});

it('replicates the source hierarchy into the tenant database', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $replica = app(TenantManager::class)->run(
            $tenant,
            fn (): array => TenantSourceType::query()->orderBy('authority_rank')->pluck('key')->all(),
        );

        expect($replica)->toHaveCount(10)
            ->and($replica[0])->toBe(SourceTypeKey::Ecn->value)
            ->and($tenant->reference_version)->toBe(1);
    });
});

it('links local facts to local sources and keeps them out of central', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $wardOfficeId = (string) Str::uuid();

        app(TenantManager::class)->run($tenant, function () use ($wardOfficeId): void {
            $notice = TenantSource::query()->create([
                'source_type_key' => SourceTypeKey::LocalLevel->value,
                'title' => 'Ward office contact notice',
                'url' => 'https://example.test/notice.pdf',
                'retrieved_at' => now(),
            ]);

            TenantSourceLink::query()->create([
                'source_id' => $notice->id,
                'subject_type' => 'ward_office',
                'subject_id' => $wardOfficeId,
                'field_path' => 'phone',
                'provenance_type' => ProvenanceType::Official,
                'asserted_value' => '025-580000',
            ]);
        });

        $tenantLinks = app(TenantManager::class)->run(
            $tenant,
            fn (): int => TenantSourceLink::query()->count(),
        );

        expect($tenantLinks)->toBe(1)
            ->and(SourceLink::query()->count())->toBe(0)
            ->and(DB::connection('central')->getSchemaBuilder()->hasTable('sources'))->toBeTrue();
    });
});

it('refuses a local link to a source that is not in this tenant', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $centralSource = Source::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($centralSource): void {
            // Pointing at a central source while claiming it is local
            expect(fn () => TenantSourceLink::query()->create([
                'source_scope' => SourceScope::Tenant,
                'source_id' => $centralSource->id,
                'subject_type' => 'office_holding',
                'subject_id' => (string) Str::uuid(),
                'provenance_type' => ProvenanceType::Official,
            ]))->toThrow(Illuminate\Database\QueryException::class);
        });
    });
});

it('links local facts to national sources across databases', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $results = Source::factory()->ofType(SourceTypeKey::Ecn)->create([
            'title' => 'Local election results 2079',
        ]);

        $resolved = app(TenantManager::class)->run($tenant, function () use ($results): ?string {
            $link = TenantSourceLink::query()->create([
                'source_scope' => SourceScope::Central,
                'source_id' => $results->id,
                'subject_type' => 'office_holding',
                'subject_id' => (string) Str::uuid(),
                'provenance_type' => ProvenanceType::Official,
            ]);

            return $link->resolveSource()?->title;
        });

        expect($resolved)->toBe('Local election results 2079');
    });
});

it('only accepts tenant subject types in a tenant database', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            $notice = TenantSource::query()->create([
                'source_type_key' => SourceTypeKey::WardOffice->value,
                'title' => 'Notice',
                'url' => 'https://example.test/n.pdf',
                'retrieved_at' => now(),
            ]);

            expect(fn () => TenantSourceLink::query()->create([
                'source_id' => $notice->id,
                'subject_type' => 'admin_unit', // central subject
                'subject_id' => (string) Str::uuid(),
                'provenance_type' => ProvenanceType::Official,
            ]))->toThrow(Illuminate\Database\QueryException::class);
        });
    });
});

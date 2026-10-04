<?php

declare(strict_types=1);

use App\Modules\Audit\Models\TenantAuditEvent;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Imports\Support\ImportContext;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Models\Vacancy;
use App\Modules\Offices\Models\WardOffice;
use App\Modules\Provenance\Models\TenantSource;
use App\Modules\Tenancy\Events\TenancyInitialized;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\WriteTenantProbeJob;

/*
| TenantIsolationTest (HW-E29-F03-T03, NFR-SEC-07, docs/12 §16).
|
| Two municipalities, each loaded through the importer with its own people,
| party, seats, vacancy, ward office and local document, all verified. Every
| tenant-scoped endpoint is then asked about each municipality in turn, and
| the answer must carry nothing from the other one — and every id belonging to
| the other must 404 when asked for through this one's address.
|
| The suite also fails when an endpoint is added without being classified
| here. Isolation that is tested only for the endpoints that existed when the
| test was written stops being a property of the system the first time
| somebody adds a route.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
});

/** Endpoints that resolve a tenant, and so must be proven isolated below. */
const TENANT_ROUTES = [
    'api.v1.evidence.show',
    'api.v1.local-levels.show',
    'api.v1.persons.show',
    'api.v1.wards.show',
];

/**
 * Endpoints that read central data only. They cannot leak a tenant's rows
 * because they never open one — which is asserted, not assumed.
 */
const CENTRAL_ROUTES = [
    'api.v1.health',
    'api.v1.local-levels.index',
    'api.v1.published-paths',
    'api.v1.search',
];

/**
 * One municipality's sheet. Every name, address and reference carries the
 * label, so a leak shows up as the other label appearing where it should not.
 *
 * @return array<string, list<array<string, string>>>
 */
function isolationSheet(string $path, string $label): array
{
    $title = ucfirst($label);
    $verifiers = ['verified_by_name' => 'Asha Example', 'reviewed_by_name' => 'Bikash Sample', 'verified_on' => '2026-10-02'];

    return [
        'sources.csv' => [
            ['source_ref' => "{$label}-results", 'source_type_key' => 'media', 'title' => "{$title}results notice", 'url' => "https://news.example/{$label}-results", 'retrieved_at' => '2026-10-01'],
            ['source_ref' => "{$label}-notice", 'source_type_key' => 'ward_office', 'title' => "{$title}ward notice", 'url' => "https://{$label}.example/notice", 'retrieved_at' => '2026-10-01'],
        ],
        'parties.csv' => [
            ['party_ref' => "{$label}-party", 'name_en' => "{$title}party", 'source_ref' => "{$label}-results"],
        ],
        'persons.csv' => [
            ['person_ref' => "{$label}-p1", 'full_name_en' => "{$title}name Chair", 'source_ref' => "{$label}-results"],
        ],
        'office_holdings.csv' => [
            ['person_ref' => "{$label}-p1", 'position_key' => 'ward_chair', 'constituency_path' => "{$path}/1", 'start_date' => '2022-05-30', 'party_ref' => "{$label}-party", 'source_ref' => "{$label}-results", 'field_sources' => "party_ref:{$label}-results"],
        ],
        'vacancies.csv' => [
            ['position_key' => 'ward_member_dalit_woman', 'constituency_path' => "{$path}/1", 'vacant_from' => '2022-05-30', 'reason' => 'no_candidate', 'source_ref' => "{$label}-results"],
        ],
        'ward_offices.csv' => [
            ['ward_path' => "{$path}/1", 'address_en' => "{$title} ward office", 'email' => "ward1@{$label}.example", 'source_ref' => "{$label}-notice"],
        ],
        'verifications.csv' => [
            ['source_ref' => "{$label}-results", 'subject_ref' => "person:{$label}-p1", ...$verifiers],
            ['source_ref' => "{$label}-results", 'subject_ref' => "party:{$label}-party", ...$verifiers],
            ['source_ref' => "{$label}-results", 'subject_ref' => "office_holding:{$label}-p1|ward_chair|{$path}/1|1|2022-05-30", ...$verifiers],
            ['source_ref' => "{$label}-results", 'subject_ref' => "vacancy:ward_member_dalit_woman|{$path}/1|1|2022-05-30", ...$verifiers],
            ['source_ref' => "{$label}-notice", 'subject_ref' => "ward_office:{$path}/1", ...$verifiers],
        ],
    ];
}

/**
 * Everything that identifies one municipality's data: the ids a request could
 * name, and the strings that would show up in a response that leaked them.
 *
 * @return array{path: string, label: string, person_slug: string, ids: array<string, string>, markers: list<string>}
 */
function isolationFixture(Tenant $tenant, string $label): array
{
    $path = tenantPath($tenant);

    publishAncestorsOf($tenant);

    expect(runImport(sheet(isolationSheet($path, $label)), ['--tenant' => $path]))->toBe(0);

    $personId = ImportContext::id('person', "{$label}-p1");

    $ids = inTenant($tenant, fn (): array => [
        'office_holding' => (string) OfficeHolding::query()->value('id'),
        'vacancy' => (string) Vacancy::query()->value('id'),
        'ward_office' => (string) WardOffice::query()->value('id'),
        'admin_unit' => (string) TenantAdminUnit::query()->where('slug_path', "{$path}/1")->value('id'),
        'person' => $personId,
        'party' => ImportContext::id('party', "{$label}-party"),
        'local_source' => (string) TenantSource::query()->value('id'),
    ]);

    $personSlug = (string) Person::query()->whereKey($personId)->value('slug');

    return [
        'path' => $path,
        'label' => $label,
        'person_slug' => $personSlug,
        'ids' => $ids,
        'markers' => [
            $path,
            $personSlug,
            ucfirst($label).'name',
            ucfirst($label).'party',
            ucfirst($label).' ward office',
            "{$label}.example",
            ...array_values($ids),
        ],
    ];
}

/**
 * Every request a visitor could make about one municipality, through its own
 * address, keyed by route name.
 *
 * @param  array{path: string, person_slug: string, ids: array<string, string>}  $own
 * @return array<string, list<string>>
 */
function ownRequests(array $own): array
{
    $evidence = array_map(
        fn (string $type): string => "/api/v1/evidence/{$own['path']}/{$type}/{$own['ids'][$type]}",
        ['office_holding', 'vacancy', 'ward_office', 'admin_unit', 'person', 'party'],
    );

    return [
        'api.v1.local-levels.show' => ["/api/v1/local-levels/{$own['path']}"],
        'api.v1.wards.show' => ["/api/v1/wards/{$own['path']}/1", "/api/v1/wards/{$own['path']}/2"],
        'api.v1.persons.show' => ["/api/v1/persons/{$own['path']}/{$own['person_slug']}"],
        'api.v1.evidence.show' => $evidence,
    ];
}

/**
 * Builds both municipalities and runs the assertion once in each direction:
 * A asked about with B as the outsider, then B with A.
 *
 * @param  Closure(array<string, mixed>, array<string, mixed>, Tenant, Tenant): void  $assert
 */
function acrossTwoMunicipalities(Closure $assert): void
{
    withTenantDatabase(function (Tenant $alpha) use ($assert): void {
        withTenantDatabase(function (Tenant $beta) use ($alpha, $assert): void {
            $a = isolationFixture($alpha, 'alpha');
            $b = isolationFixture($beta, 'beta');

            $assert($a, $b, $alpha, $beta);
            $assert($b, $a, $beta, $alpha);
        }, tenantWithPublishedWards());
    }, tenantWithPublishedWards());
}

// -- every endpoint is classified ------------------------------------------------

it('classifies every API route as tenant-scoped or central', function (): void {
    $tenant = [];
    $central = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'api.v1.')) {
            continue;
        }

        in_array(ResolveTenant::class, $route->gatherMiddleware(), true)
            ? $tenant[] = $name
            : $central[] = $name;
    }

    sort($tenant);
    sort($central);

    // A new endpoint fails here until it is added to one list — and, if it
    // opens a tenant, to ownRequests() so the assertions below cover it.
    expect($tenant)->toBe(TENANT_ROUTES)
        ->and($central)->toBe(CENTRAL_ROUTES);
});

// -- tenant endpoints -------------------------------------------------------------

it('answers each municipality with its own data and nothing of the other', function (): void {
    acrossTwoMunicipalities(function (array $own, array $other): void {
        $requests = ownRequests($own);

        expect(array_keys($requests))->toEqualCanonicalizing(TENANT_ROUTES);

        foreach ($requests as $route => $urls) {
            foreach ($urls as $url) {
                // Re-encoded unescaped: Laravel writes "/" as "\/" and Devanagari
                // as \u escapes, and a marker that cannot match proves nothing.
                $body = json_encode(
                    $this->getJson($url)->assertOk()->json(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );

                // The request reached the right municipality…
                expect($body)->toContain(match ($route) {
                    // Evidence can be empty (a ward nobody has cited yet), so
                    // the proof it answered is that it names the subject asked for.
                    'api.v1.evidence.show' => basename($url),
                    'api.v1.persons.show' => ucfirst($own['label']).'name',
                    default => $own['path'],
                });

                // …and said nothing about the other one.
                foreach ($other['markers'] as $marker) {
                    expect($body)->not->toContain($marker);
                }
            }
        }
    });
});

it('refuses every id of one municipality through the other one\'s address', function (): void {
    acrossTwoMunicipalities(function (array $own, array $other): void {
        $this->getJson("/api/v1/persons/{$own['path']}/{$other['person_slug']}")->assertNotFound();

        foreach (['office_holding', 'vacancy', 'ward_office', 'admin_unit', 'person'] as $type) {
            $this->getJson("/api/v1/evidence/{$own['path']}/{$type}/{$other['ids'][$type]}")
                ->assertNotFound();
        }

        // A party is national: the same party stands in every municipality,
        // and its evidence is the same under any of their addresses. Stated
        // here so that changing it is a decision rather than a regression.
        $this->getJson("/api/v1/evidence/{$own['path']}/party/{$other['ids']['party']}")->assertOk();
    });
});

it('ends the tenant after every request, so the next one starts clean', function (): void {
    acrossTwoMunicipalities(function (array $own): void {
        $this->getJson("/api/v1/wards/{$own['path']}/1")->assertOk();

        expect(app(TenantManager::class)->initialized())->toBeFalse();
    });
});

// -- central endpoints ---------------------------------------------------------------

it('serves the central endpoints without opening any tenant', function (): void {
    acrossTwoMunicipalities(function (array $own): void {
        $initialized = [];

        app('events')->listen(TenancyInitialized::class, function ($event) use (&$initialized): void {
            $initialized[] = $event->tenant->getKey();
        });

        foreach (['/api/v1/health', '/api/v1/local-levels', '/api/v1/published-paths', '/api/v1/search?q='.urlencode($own['path'])] as $url) {
            $this->getJson($url)->assertOk();
        }

        expect($initialized)->toBe([]);
    });
});

// -- jobs -------------------------------------------------------------------------------

it('runs each queued job in the municipality it was dispatched from', function (): void {
    config(['queue.default' => 'database']);

    acrossTwoMunicipalities(function (array $own, array $other, Tenant $ownTenant, Tenant $otherTenant): void {
        $tenancy = app(TenantManager::class);

        // Dispatched from inside each tenant; statement bodies, because a
        // PendingDispatch dispatches when it is destroyed.
        $tenancy->run($ownTenant, function () use ($own): void {
            WriteTenantProbeJob::dispatch($own['label']);
        });
        $tenancy->run($otherTenant, function () use ($other): void {
            WriteTenantProbeJob::dispatch($other['label']);
        });

        // The worker is left inside the OTHER tenant before it picks up the
        // first job: what it handles must follow the job, not the worker.
        $tenancy->initialize($otherTenant);

        // Drain everything: the imports that built the fixture queued their
        // own outbox jobs, and those must keep to their tenants as well. The
        // worker shares the test process, whose memory late in a full run is
        // past the default 128 MB, where a worker stops with exit code 12.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--queue' => 'default', '--memory' => 1024])
            ->assertSuccessful()
            ->run();

        $tenancy->end();

        $probe = fn (): mixed => json_decode(
            (string) DB::connection('tenant')->table('settings')->where('key', 'probe')->value('value'),
            true,
        );

        expect(inTenant($ownTenant, $probe))->toBe(['message' => $own['label']])
            ->and(inTenant($otherTenant, $probe))->toBe(['message' => $other['label']]);
    });
});

// -- the importer -----------------------------------------------------------------------

it('keeps each import inside the municipality it named', function (): void {
    acrossTwoMunicipalities(function (array $own, array $other, Tenant $ownTenant): void {
        inTenant($ownTenant, function () use ($own, $other): void {
            expect(OfficeHolding::query()->pluck('person_id')->all())->toBe([$own['ids']['person']])
                ->and(TenantSource::query()->pluck('id')->all())->toBe([$own['ids']['local_source']])
                ->and(TenantAuditEvent::query()->whereIn('subject_id', array_values($other['ids']))->exists())->toBeFalse();
        });
    });
});

// -- schema guard (HW-E29-F03-T01) -----------------------------------------------------

it('puts only the municipality whose schema is behind into maintenance', function (): void {
    acrossTwoMunicipalities(function (array $own, array $other, Tenant $ownTenant, Tenant $otherTenant): void {
        // What a deploy leaves behind when hw:tenant:migrate stops at an
        // earlier municipality's failure: still active, on the old schema.
        $current = $ownTenant->schema_version;
        $ownTenant->forceFill(['schema_version' => '2026_01_01_000000_an_older_schema'])->save();

        $this->getJson("/api/v1/wards/{$own['path']}/1")->assertStatus(503);
        $this->getJson("/api/v1/wards/{$other['path']}/1")->assertOk();

        $ownTenant->forceFill(['schema_version' => $current])->save();
    });
});

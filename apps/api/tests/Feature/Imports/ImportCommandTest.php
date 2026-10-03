<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Models\TenantAuditEvent;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitAlias;
use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Imports\Support\ImportContext;
use App\Modules\Imports\Support\ImportFile;
use App\Modules\Offices\Enums\SeatState;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Models\Vacancy;
use App\Modules\Offices\Models\WardOffice;
use App\Modules\Offices\Queries\CurrentSeatsQuery;
use App\Modules\Provenance\Models\Source;
use App\Modules\Provenance\Models\SourceLink;
use App\Modules\Provenance\Models\TenantSourceLink;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| hw:import (HW-E06-F02-T01). The acceptance criteria, in the backlog's words:
|   - all files in docs/05 §13 supported; central and tenant rows routed correctly
|   - --dry-run changes nothing and reports row-level errors
|   - re-running the same import is a no-op
|   - the tenant part runs in one transaction and emits audit events
|
| Every name, party and document here is invented. Sources use the media type
| and the reserved .example domain: test data must not borrow a real
| institution's authority any more than demonstration data may (docs/13 §4).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
});

afterEach(function (): void {
    foreach ($GLOBALS['hw_import_sheets'] ?? [] as $directory) {
        File::deleteDirectory($directory);
    }

    $GLOBALS['hw_import_sheets'] = [];
});

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
    $GLOBALS['hw_import_sheets'][] = $directory;

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

/** @param  array<string, mixed>  $options */
function runImport(string $directory, array $options = []): int
{
    $report = $directory.'/report.md';

    return test()->artisan('hw:import', ['directory' => $directory, '--force' => true, '--report' => $report, ...$options])
        ->run();
}

function lastReport(string $directory): string
{
    return (string) file_get_contents($directory.'/report.md');
}

/**
 * A province, district, municipality and two wards, as a geography sheet.
 *
 * @return array<string, list<array<string, string>>>
 */
function geographySheet(): array
{
    return [
        'admin_units.csv' => [
            // Children first on purpose: the importer orders by level itself.
            ['level' => 'ward', 'parent_path' => 'koshi/sunsari/namuna', 'slug' => '2', 'name_ne' => 'वडा नं. २', 'name_en' => 'Ward 2', 'ward_number' => '2'],
            ['level' => 'ward', 'parent_path' => 'koshi/sunsari/namuna', 'slug' => '1', 'name_ne' => 'वडा नं. १', 'name_en' => 'Ward 1', 'ward_number' => '1'],
            ['level' => 'local_level', 'parent_path' => 'koshi/sunsari', 'slug' => 'namuna', 'name_ne' => 'नमुना नगरपालिका', 'name_en' => 'Namuna Municipality', 'local_level_type' => 'municipality', 'cbs_code' => '99901'],
            ['level' => 'district', 'parent_path' => 'koshi', 'slug' => 'sunsari', 'name_ne' => 'सुनसरी', 'name_en' => 'Sunsari'],
            ['level' => 'province', 'slug' => 'koshi', 'name_ne' => 'कोशी प्रदेश', 'name_en' => 'Koshi Province'],
            ['level' => 'country', 'slug' => 'nepal', 'name_ne' => 'नेपाल', 'name_en' => 'Nepal'],
        ],
        'aliases.csv' => [
            ['unit_path' => 'koshi/sunsari/namuna', 'alias' => 'Namuna Nagarpalika', 'kind' => 'variant'],
            ['unit_path' => 'koshi/sunsari/namuna', 'alias' => 'नमूना', 'kind' => 'misspelling'],
        ],
    ];
}

/**
 * A complete municipality sheet for the tenant at $path: one verified ward
 * chair, one cited-but-unverified member, one verified vacancy, one ward office.
 *
 * @return array<string, list<array<string, string>>>
 */
function municipalitySheet(string $path): array
{
    $verifiers = ['verified_by_name' => 'Asha Example', 'reviewed_by_name' => 'Bikash Sample', 'verified_on' => '2026-10-02'];
    $chair = "office_holding:imp-p1|ward_chair|{$path}/1|1|2022-05-30";

    return [
        'sources.csv' => [
            ['source_ref' => 'imp-results', 'source_type_key' => 'media', 'title' => 'नतिजा (नमुना)', 'url' => 'https://news.example/results', 'published_at' => '2022-05-20', 'published_as_written' => '२०७९ जेठ ६', 'retrieved_at' => '2026-10-01'],
            ['source_ref' => 'imp-notice', 'source_type_key' => 'ward_office', 'title' => 'वडा सूचना (नमुना)', 'url' => 'https://ward.example/notice', 'retrieved_at' => '2026-10-01T09:30:00Z'],
        ],
        'parties.csv' => [
            ['party_ref' => 'imp-party', 'name_ne' => 'नमुना दल', 'name_en' => 'Example Party', 'source_ref' => 'imp-results'],
        ],
        'persons.csv' => [
            ['person_ref' => 'imp-p1', 'full_name_ne' => 'सरिता उदाहरण', 'full_name_en' => 'Sarita Udaharan', 'source_ref' => 'imp-results'],
            ['person_ref' => 'imp-p2', 'full_name_ne' => 'रमेश नमुना', 'full_name_en' => 'Ramesh Namuna', 'source_ref' => 'imp-results'],
        ],
        'office_holdings.csv' => [
            ['person_ref' => 'imp-p1', 'position_key' => 'ward_chair', 'constituency_path' => "{$path}/1", 'seat_index' => '1', 'start_date' => '2022-05-30', 'party_ref' => 'imp-party', 'term_label' => '2022–2027', 'source_ref' => 'imp-results', 'field_sources' => 'party_ref:imp-results'],
            ['person_ref' => 'imp-p2', 'position_key' => 'ward_member_open', 'constituency_path' => "{$path}/1", 'seat_index' => '1', 'start_date' => '2022-05-30', 'is_independent' => 'true', 'source_ref' => 'imp-results'],
        ],
        'vacancies.csv' => [
            ['position_key' => 'ward_member_dalit_woman', 'constituency_path' => "{$path}/1", 'seat_index' => '1', 'vacant_from' => '2022-05-30', 'reason' => 'no_candidate', 'source_ref' => 'imp-results'],
        ],
        'ward_offices.csv' => [
            ['ward_path' => "{$path}/1", 'address_ne' => 'नमुना-१', 'phone' => '025-580001', 'email' => 'ward1@namuna.example', 'lat' => '26.8124', 'lng' => '87.2836', 'source_ref' => 'imp-notice'],
        ],
        'verifications.csv' => [
            ['source_ref' => 'imp-results', 'subject_ref' => 'person:imp-p1', ...$verifiers],
            ['source_ref' => 'imp-results', 'subject_ref' => 'party:imp-party', ...$verifiers],
            ['source_ref' => 'imp-results', 'subject_ref' => $chair, ...$verifiers],
            ['source_ref' => 'imp-results', 'subject_ref' => $chair, 'field' => 'party_ref', ...$verifiers],
            ['source_ref' => 'imp-results', 'subject_ref' => "vacancy:ward_member_dalit_woman|{$path}/1|1|2022-05-30", ...$verifiers],
            ['source_ref' => 'imp-notice', 'subject_ref' => "ward_office:{$path}/1", ...$verifiers],
        ],
    ];
}

function tenantPath(Tenant $tenant): string
{
    return (string) AdminUnitSlug::query()
        ->where('admin_unit_id', $tenant->admin_unit_id)
        ->where('is_current', true)
        ->value('slug_path');
}

function inTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantManager::class)->run($tenant, $callback, allowInactive: true);
}

// -- geography ------------------------------------------------------------------

it('dry-runs a geography sheet without writing anything', function (): void {
    $directory = sheet(geographySheet());

    expect(runImport($directory, ['--dry-run' => true]))->toBe(0)
        ->and(AdminUnit::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0)
        ->and(lastReport($directory))
        ->toContain('DRY RUN — clean')
        ->toContain('| admin_unit | 6 | 0 | 0 |');
});

it('imports geography unpublished, with slug paths, codes and aliases', function (): void {
    expect(runImport(sheet(geographySheet())))->toBe(0);

    $namuna = AdminUnit::query()->where('slug', 'namuna')->sole();

    expect(AdminUnitSlug::query()->where('is_current', true)->pluck('slug_path')->sort()->values()->all())
        ->toBe(['koshi', 'koshi/sunsari', 'koshi/sunsari/namuna', 'koshi/sunsari/namuna/1', 'koshi/sunsari/namuna/2'])
        // Going public is the operator's decision (D-006), never an import's.
        ->and(AdminUnit::query()->where('is_published', true)->count())->toBe(0)
        ->and($namuna->codes()->value('code'))->toBe('99901')
        ->and(AdminUnitAlias::query()->where('admin_unit_id', $namuna->id)->count())->toBe(2)
        ->and(AdminUnitAlias::query()->where('alias', 'नमूना')->value('script')?->value)->toBe('deva')
        ->and(AuditEvent::query()->where('action', 'admin_unit.created')->count())->toBe(6)
        ->and(AuditEvent::query()->where('action', 'import.completed')->count())->toBe(1);
});

it('is a no-op when the same sheet is imported twice', function (): void {
    $directory = sheet(geographySheet());

    runImport($directory);
    $events = AuditEvent::query()->count();
    $updated = AdminUnit::query()->max('updated_at');

    expect(runImport($directory))->toBe(0)
        ->and(AuditEvent::query()->count())->toBe($events)
        ->and(AdminUnit::query()->max('updated_at'))->toEqual($updated)
        ->and(lastReport($directory))->toContain('| admin_unit | 0 | 0 | 6 |');
});

it('updates a corrected name in place and audits before and after', function (): void {
    runImport(sheet(geographySheet()));

    $sheet = geographySheet();
    $sheet['admin_units.csv'][2]['name_en'] = 'Namuna Municipality (corrected)';
    $directory = sheet($sheet);

    expect(runImport($directory))->toBe(0)
        ->and(AdminUnit::query()->where('slug', 'namuna')->value('name_en'))->toBe('Namuna Municipality (corrected)');

    $event = AuditEvent::query()->where('action', 'admin_unit.updated')->sole();

    expect($event->changes)->toEqual([
        'before' => ['name_en' => 'Namuna Municipality'],
        'after' => ['name_en' => 'Namuna Municipality (corrected)'],
    ]);
});

// -- refusal --------------------------------------------------------------------

it('refuses the whole sheet when one row is wrong, and names the cell', function (): void {
    $sheet = geographySheet();
    $sheet['admin_units.csv'][] = ['level' => 'ward', 'parent_path' => 'koshi/sunsari/namuna', 'slug' => '3', 'name_en' => 'Ward 3', 'ward_number' => '4'];
    $sheet['admin_units.csv'][] = ['level' => 'district', 'parent_path' => 'koshi', 'slug' => 'morang', 'name_en' => 'Morang', 'valid_from' => '2081-01-01'];
    $directory = sheet($sheet);

    expect(runImport($directory))->toBe(1)
        // The good rows were valid and still nothing landed: one transaction.
        ->and(AdminUnit::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0)
        ->and(lastReport($directory))
        ->toContain('REFUSED')
        ->toContain('admin_units.csv row 8, slug: a ward\'s slug is its number; expected "4".')
        ->toContain('admin_units.csv row 9, valid_from: "2081-01-01" looks like a BS date.');
});

it('refuses a file whose header is not exactly the documented one', function (): void {
    $directory = sheet(geographySheet());
    file_put_contents($directory.'/aliases.csv', "unit_path,alias,kind,script\nkoshi,Kosi,variant,latn\n");

    expect(runImport($directory))->toBe(1)
        ->and(AdminUnit::query()->count())->toBe(0)
        ->and(lastReport($directory))->toContain('aliases.csv row 1: the header row must be exactly "unit_path,alias,script,kind"');
});

it('reads a byte-order mark and decomposed Devanagari as the same text', function (): void {
    $directory = sheet(geographySheet());
    // "नमूना" with the vowel sign written as a separate code point sequence
    // is still NFC here; build an NFD string to prove normalisation runs.
    $nfd = Normalizer::normalize('क़ानून', Normalizer::FORM_D);
    file_put_contents(
        $directory.'/aliases.csv',
        "\u{FEFF}unit_path,alias,script,kind\nkoshi/sunsari/namuna,{$nfd},deva,legacy\n",
    );

    expect(runImport($directory))->toBe(0)
        ->and(AdminUnitAlias::query()->where('kind', 'legacy')->value('alias'))
        ->toBe(Normalizer::normalize('क़ानून', Normalizer::FORM_C));
});

it('refuses an unrecognised file name rather than skipping it', function (): void {
    $directory = sheet(geographySheet());
    file_put_contents($directory.'/admin_unit.csv', "level\n");

    expect(runImport($directory))->toBe(1)
        ->and(AdminUnit::query()->count())->toBe(0);
});

it('refuses tenant files without --tenant, and geography with it', function (): void {
    expect(runImport(sheet(['ward_offices.csv' => []])))->toBe(1);

    withTenantDatabase(function (Tenant $tenant): void {
        expect(runImport(sheet(geographySheet()), ['--tenant' => tenantPath($tenant)]))->toBe(1)
            ->and(AdminUnit::query()->where('slug', 'namuna')->exists())->toBeFalse();
    }, tenantWithPublishedWards());
});

it('refuses a national record cited to a municipality document', function (): void {
    $directory = sheet([
        'sources.csv' => [
            ['source_ref' => 'local-notice', 'source_type_key' => 'ward_office', 'title' => 'Notice', 'url' => 'https://ward.example/n', 'retrieved_at' => '2026-10-01'],
        ],
        'persons.csv' => [
            ['person_ref' => 'someone', 'full_name_en' => 'Some One', 'source_ref' => 'local-notice'],
        ],
    ]);

    expect(runImport($directory))->toBe(1)
        ->and(Person::query()->count())->toBe(0)
        ->and(lastReport($directory))
        ->toContain('sources.csv row 2')
        ->toContain('is a municipality document, so it cannot back a national record');
});

// -- a municipality ---------------------------------------------------------------

it('imports a municipality: seats, evidence, verifications and both audit trails', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        $directory = sheet(municipalitySheet($path));

        expect(runImport($directory, ['--tenant' => $path]))->toBe(0);

        $sarita = Person::query()->findOrFail(ImportContext::id('person', 'imp-p1'));
        $ramesh = Person::query()->findOrFail(ImportContext::id('person', 'imp-p2'));

        // Published only where a verified source backs the record (FR-SRC-02).
        expect($sarita->is_published)->toBeTrue()
            ->and($ramesh->is_published)->toBeFalse()
            ->and(Party::query()->findOrFail(ImportContext::id('party', 'imp-party'))->is_published)->toBeTrue()
            // National source in central; the ward office's notice in the tenant.
            ->and(Source::query()->whereKey(ImportContext::id('source', 'imp-results'))->exists())->toBeTrue()
            ->and(Source::query()->whereKey(ImportContext::id('source', 'imp-notice'))->exists())->toBeFalse()
            ->and(SourceLink::query()->where('subject_type', 'person')->where('verification_status', 'verified')->count())->toBe(1)
            ->and(AuditEvent::query()->where('action', 'import.completed')->count())->toBe(1);

        $runId = AuditEvent::query()->where('action', 'import.completed')->value('request_id');

        inTenant($tenant, function () use ($path, $runId): void {
            $ward = TenantAdminUnit::query()->where('slug_path', "{$path}/1")->sole();
            $seats = app(CurrentSeatsQuery::class)->forConstituency($ward->id)
                ->mapWithKeys(fn ($seat) => ["{$seat->positionKey}#{$seat->seatIndex}" => $seat->state]);

            expect($seats['ward_chair#1'])->toBe(SeatState::Held)
                ->and($seats['ward_member_dalit_woman#1'])->toBe(SeatState::Vacant)
                // Cited, not verified: still "not yet verified", name or no name.
                ->and($seats['ward_member_open#1'])->toBe(SeatState::NotVerified)
                ->and(OfficeHolding::query()->count())->toBe(2)
                ->and(Vacancy::query()->count())->toBe(1);

            $partyLink = TenantSourceLink::query()->where('field_path', 'party_id')->sole();

            expect($partyLink->source_scope->value)->toBe('central')
                ->and($partyLink->asserted_value)->toBe('Example Party')
                ->and($partyLink->isVerified())->toBeTrue()
                ->and($partyLink->verified_by)->toBe(ImportContext::staffId('Asha Example'))
                ->and($partyLink->second_approved_by)->toBe(ImportContext::staffId('Bikash Sample'))
                ->and(DB::connection('tenant')->table('ward_offices')->selectRaw('ST_AsEWKT(location) AS p')->value('p'))
                ->toBe('SRID=4326;POINT(87.2836 26.8124)')
                ->and(TenantAuditEvent::query()->where('action', 'office_holding.created')->count())->toBe(2)
                ->and(TenantAuditEvent::query()->where('request_id', $runId)->where('action', 'import.completed')->exists())->toBeTrue();
        });
    }, tenantWithPublishedWards());
});

it('changes nothing in either database on a municipality dry run', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);

        expect(runImport(sheet(municipalitySheet($path)), ['--tenant' => $path, '--dry-run' => true]))->toBe(0)
            ->and(Person::query()->count())->toBe(0)
            ->and(Source::query()->count())->toBe(0)
            ->and(AuditEvent::query()->count())->toBe(0);

        inTenant($tenant, fn () => expect(OfficeHolding::query()->count())->toBe(0)
            ->and(WardOffice::query()->count())->toBe(0)
            ->and(TenantSourceLink::query()->count())->toBe(0)
            ->and(TenantAuditEvent::query()->count())->toBe(0));
    }, tenantWithPublishedWards());
});

it('is a no-op when the same municipality sheet is imported twice', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        $directory = sheet(municipalitySheet($path));

        runImport($directory, ['--tenant' => $path]);
        $central = AuditEvent::query()->count();
        $local = inTenant($tenant, fn () => TenantAuditEvent::query()->count());

        expect(runImport($directory, ['--tenant' => $path]))->toBe(0)
            ->and(AuditEvent::query()->count())->toBe($central)
            ->and(inTenant($tenant, fn () => TenantAuditEvent::query()->count()))->toBe($local)
            ->and(lastReport($directory))
            ->toContain('| office_holding | 0 | 0 | 2 |')
            ->toContain('| ward_office | 0 | 0 | 1 |')
            ->toContain('| verification | 0 | 0 | 6 |');
    }, tenantWithPublishedWards());
});

it('refuses a second holder of a held seat in words the sheet author can act on', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        $sheet = municipalitySheet($path);
        $sheet['office_holdings.csv'][] = ['person_ref' => 'imp-p2', 'position_key' => 'ward_chair', 'constituency_path' => "{$path}/1", 'seat_index' => '1', 'start_date' => '2023-01-01'];
        $directory = sheet($sheet);

        expect(runImport($directory, ['--tenant' => $path]))->toBe(1)
            ->and(lastReport($directory))->toContain('office_holdings.csv row 4: someone else holds this seat over an overlapping period.')
            ->and(Person::query()->count())->toBe(0);

        inTenant($tenant, fn () => expect(OfficeHolding::query()->count())->toBe(0));
    }, tenantWithPublishedWards());
});

it('refuses a verification signed twice by the same person (D-002)', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        $sheet = municipalitySheet($path);
        $sheet['verifications.csv'][0]['reviewed_by_name'] = '  asha   EXAMPLE ';
        $directory = sheet($sheet);

        expect(runImport($directory, ['--tenant' => $path]))->toBe(1)
            ->and(lastReport($directory))->toContain('verifications.csv row 2, reviewed_by_name: must be a different person from verified_by_name (D-002)');
    }, tenantWithPublishedWards());
});

it('refuses a verification of a citation that was never made', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        $sheet = municipalitySheet($path);
        $sheet['verifications.csv'][] = ['source_ref' => 'imp-results', 'subject_ref' => "office_holding:imp-p1|ward_chair|{$path}/1|1|2022-05-30", 'field' => 'term_label', 'verified_by_name' => 'A', 'reviewed_by_name' => 'B', 'verified_on' => '2026-10-02'];
        $directory = sheet($sheet);

        expect(runImport($directory, ['--tenant' => $path]))->toBe(1)
            ->and(lastReport($directory))->toContain('does not cite "imp-results" for term_label');
    }, tenantWithPublishedWards());
});

it('refuses swapped coordinates instead of pinning a ward office outside Nepal', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        $sheet = municipalitySheet($path);
        $sheet['ward_offices.csv'][0]['lat'] = '87.2836';
        $sheet['ward_offices.csv'][0]['lng'] = '26.8124';
        $directory = sheet($sheet);

        expect(runImport($directory, ['--tenant' => $path]))->toBe(1)
            ->and(lastReport($directory))->toContain('is outside Nepal. Check that lat and lng are not swapped.');
    }, tenantWithPublishedWards());
});

it('warns when a re-import renames a person, since refs are global', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        runImport(sheet(municipalitySheet($path)), ['--tenant' => $path]);

        $sheet = municipalitySheet($path);
        $sheet['persons.csv'][1]['full_name_en'] = 'Somebody Else';
        $directory = sheet($sheet);

        expect(runImport($directory, ['--tenant' => $path, '--dry-run' => true]))->toBe(0)
            ->and(lastReport($directory))->toContain('person "imp-p2" is renamed from "रमेश नमुना / Ramesh Namuna" to "रमेश नमुना / Somebody Else"');
    }, tenantWithPublishedWards());
});

// -- the audit trail itself ------------------------------------------------------

it('keeps audit_events append-only for every role', function (): void {
    runImport(sheet(geographySheet()));
    $event = AuditEvent::query()->firstOrFail();

    expectRejectedByDatabase(fn () => DB::connection('central')->table('audit_events')->where('id', $event->id)->update(['action' => 'tampered.with']));
    expectRejectedByDatabase(fn () => DB::connection('central')->table('audit_events')->where('id', $event->id)->delete());

    expect(AuditEvent::query()->whereKey($event->id)->value('action'))->toBe($event->action);
});

it('records importer events against the level a unit was created at', function (): void {
    runImport(sheet(geographySheet()));

    $ward = AdminUnit::query()->where('level', AdminLevel::Ward->value)->where('slug', '1')->sole();

    expect(AuditEvent::query()->where('subject_type', 'admin_unit')->where('subject_id', $ward->id)->value('actor_type')?->value)
        ->toBe('importer');
});

it('ships header templates that match what the importer requires', function (): void {
    foreach (ImportFile::cases() as $file) {
        $template = dirname(base_path(), 2).'/data/templates/'.$file->value;

        expect(file_exists($template))->toBeTrue("{$file->value} has no template")
            ->and(trim((string) file_get_contents($template)))->toBe(implode(',', $file->columns()));
    }
});

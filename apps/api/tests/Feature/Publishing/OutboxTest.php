<?php

declare(strict_types=1);

use App\Modules\Imports\Support\ImportContext;
use App\Modules\Offices\Actions\EndOfficeHolding;
use App\Modules\Offices\Enums\HoldingEndReason;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Person;
use App\Modules\Publishing\Jobs\DispatchTenantOutbox;
use App\Modules\Publishing\Models\ProcessedOutboxEvent;
use App\Modules\Publishing\Models\PublicEntity;
use App\Modules\Tenancy\Actions\RecordOutboxEvent;
use App\Modules\Tenancy\Models\OutboxEvent;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
| The tenant outbox and the central index of person pages (HW-E29-F03-T02,
| docs/12 §4.3). Acceptance criteria, in the backlog's words:
|   - outbox row written in the same transaction as the change
|   - duplicate event processing is a no-op
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
});

/**
 * Two ward members: one verified (so published, so with a page), one only
 * cited (unpublished, so without one).
 *
 * @return array<string, list<array<string, string>>>
 */
function outboxSheet(string $path): array
{
    $verifiers = ['verified_by_name' => 'Asha Example', 'reviewed_by_name' => 'Bikash Sample', 'verified_on' => '2026-10-02'];

    return [
        'sources.csv' => [
            ['source_ref' => 'ob-results', 'source_type_key' => 'media', 'title' => 'Results (example)', 'url' => 'https://news.example/ob', 'retrieved_at' => '2026-10-01'],
        ],
        'persons.csv' => [
            ['person_ref' => 'ob-p1', 'full_name_en' => 'Verified Member', 'source_ref' => 'ob-results'],
            ['person_ref' => 'ob-p2', 'full_name_en' => 'Cited Member', 'source_ref' => 'ob-results'],
        ],
        'office_holdings.csv' => [
            ['person_ref' => 'ob-p1', 'position_key' => 'ward_chair', 'constituency_path' => "{$path}/1", 'start_date' => '2022-05-30', 'is_independent' => 'true', 'source_ref' => 'ob-results'],
            ['person_ref' => 'ob-p2', 'position_key' => 'ward_member_open', 'constituency_path' => "{$path}/1", 'start_date' => '2022-05-30', 'is_independent' => 'true', 'source_ref' => 'ob-results'],
        ],
        'verifications.csv' => [
            ['source_ref' => 'ob-results', 'subject_ref' => 'person:ob-p1', ...$verifiers],
            ['source_ref' => 'ob-results', 'subject_ref' => "office_holding:ob-p1|ward_chair|{$path}/1|1|2022-05-30", ...$verifiers],
        ],
    ];
}

/** @return list<PublicEntity> */
function personPages(Tenant $tenant): array
{
    return PublicEntity::query()->where('tenant_id', $tenant->id)->where('entity_type', 'person')->get()->all();
}

it('writes one outbox event per changed holding, inside the import, and none on a re-run', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        $directory = sheet(outboxSheet($path));

        runImport($directory, ['--tenant' => $path]);

        $events = inTenant($tenant, fn () => OutboxEvent::query()->get());

        expect($events)->toHaveCount(2)
            ->and($events->pluck('event_type')->unique()->all())->toBe([RecordOutboxEvent::HOLDING_CHANGED])
            ->and($events->pluck('payload.person_id')->sort()->values()->all())
            ->toBe(collect([ImportContext::id('person', 'ob-p1'), ImportContext::id('person', 'ob-p2')])->sort()->values()->all());

        runImport($directory, ['--tenant' => $path]);

        expect(inTenant($tenant, fn () => OutboxEvent::query()->count()))->toBe(2);
    }, tenantWithPublishedWards());
});

it('writes no outbox event when the import is a dry run or is refused', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);

        runImport(sheet(outboxSheet($path)), ['--tenant' => $path, '--dry-run' => true]);

        $refused = outboxSheet($path);
        $refused['office_holdings.csv'][] = ['person_ref' => 'nobody', 'position_key' => 'ward_chair', 'constituency_path' => "{$path}/2", 'start_date' => '2022-05-30'];
        runImport(sheet($refused), ['--tenant' => $path]);

        // The holding and its event share one transaction: no holding, no event.
        expect(inTenant($tenant, fn () => [OutboxEvent::query()->count(), OfficeHolding::query()->count()]))->toBe([0, 0]);
    }, tenantWithPublishedWards());
});

it('indexes the page of a published person who holds a seat, and only that one', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        publishAncestorsOf($tenant);

        runImport(sheet(outboxSheet($path)), ['--tenant' => $path]);

        $verified = Person::query()->findOrFail(ImportContext::id('person', 'ob-p1'));
        $pages = personPages($tenant);

        // The cited-but-unverified member is unpublished, so has no page.
        expect($pages)->toHaveCount(1)
            ->and($pages[0]->entity_id)->toBe($verified->id)
            ->and($pages[0]->path_en)->toBe("person/{$path}/{$verified->slug}")
            ->and($pages[0]->is_published)->toBeTrue()
            ->and(inTenant($tenant, fn () => OutboxEvent::query()->whereNull('processed_at')->count()))->toBe(0);

        // And the address it lists is one the API serves.
        $this->getJson("/api/v1/persons/{$path}/{$verified->slug}")->assertOk();
    }, tenantWithPublishedWards());
});

it('treats a second processing of the same event as a no-op, even after a crash', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        runImport(sheet(outboxSheet($path)), ['--tenant' => $path]);

        $page = personPages($tenant)[0];
        $processed = ProcessedOutboxEvent::query()->count();

        // The crash case: central applied and recorded the events, then the
        // worker died before stamping the tenant rows.
        inTenant($tenant, fn () => OutboxEvent::query()->update(['processed_at' => null]));
        Carbon::setTestNow(now()->addHour());

        DispatchTenantOutbox::dispatchSync($tenant->id);
        DispatchTenantOutbox::dispatchSync($tenant->id);

        Carbon::setTestNow();

        expect(ProcessedOutboxEvent::query()->count())->toBe($processed)
            ->and(personPages($tenant))->toHaveCount(1)
            // Not re-applied: the page did not move.
            ->and(personPages($tenant)[0]->lastmod->equalTo($page->lastmod))->toBeTrue()
            ->and(inTenant($tenant, fn () => OutboxEvent::query()->whereNull('processed_at')->count()))->toBe(0);
    }, tenantWithPublishedWards());
});

it('unpublishes the page when the term ends', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        runImport(sheet(outboxSheet($path)), ['--tenant' => $path]);

        $personId = ImportContext::id('person', 'ob-p1');

        inTenant($tenant, function () use ($personId): void {
            app(EndOfficeHolding::class)->handle(
                OfficeHolding::query()->where('person_id', $personId)->firstOrFail(),
                Carbon::parse('2025-01-15'),
                HoldingEndReason::Resignation,
            );
        });

        DispatchTenantOutbox::dispatchSync($tenant->id);

        expect(personPages($tenant)[0]->is_published)->toBeFalse();
        $this->getJson('/api/v1/published-paths')->assertOk()->assertJsonMissing(['path' => personPages($tenant)[0]->path_en]);
    }, tenantWithPublishedWards());
});

it('leaves an event of an unknown type pending rather than marking it done', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        inTenant($tenant, fn () => app(RecordOutboxEvent::class)->handle('issue.state_changed', 'issue', fake()->uuid()));

        DispatchTenantOutbox::dispatchSync($tenant->id);

        expect(inTenant($tenant, fn () => OutboxEvent::query()->whereNull('processed_at')->count()))->toBe(1)
            ->and(ProcessedOutboxEvent::query()->count())->toBe(0);
    }, tenantWithPublishedWards());
});

it('picks up a person published centrally on the next rebuild', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        runImport(sheet(outboxSheet($path)), ['--tenant' => $path]);

        // Published by a central change — no tenant row moved, so no event.
        Person::query()->findOrFail(ImportContext::id('person', 'ob-p2'))
            ->forceFill(['is_published' => true, 'published_at' => now()])->save();

        expect(personPages($tenant))->toHaveCount(1);

        $this->artisan('hw:index:rebuild')->assertSuccessful();

        expect(personPages($tenant))->toHaveCount(2);
    }, tenantWithPublishedWards());
});

it('lists each municipality\'s person pages in published paths', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        runImport(sheet(outboxSheet($path)), ['--tenant' => $path]);

        // Published paths only list municipalities whose ancestors are public.
        publishAncestorsOf($tenant);

        $entry = $this->getJson('/api/v1/published-paths')->assertOk()->collect('data')->firstWhere('slug_path', $path);

        expect($entry['people'])->toHaveCount(1)
            ->and($entry['people'][0]['path'])->toBe(personPages($tenant)[0]->path_en)
            ->and($entry['people'][0]['updated_at'])->not->toBeNull();
    }, tenantWithPublishedWards());
});

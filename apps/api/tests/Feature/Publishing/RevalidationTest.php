<?php

declare(strict_types=1);

use App\Modules\Imports\Support\ImportContext;
use App\Modules\Offices\Actions\EndOfficeHolding;
use App\Modules\Offices\Enums\HoldingEndReason;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Publishing\Jobs\DispatchRevalidation;
use App\Modules\Publishing\Jobs\DispatchTenantOutbox;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| Signed on-demand revalidation (HW-E08-F01-T04). Acceptance criteria:
|   - HMAC-SHA256 over timestamp+body; ±5 min window; rejects invalid
|     (the window and the rejection are the web route's, tested in
|     apps/web/src/lib/__tests__/revalidate.test.ts)
|   - import triggers revalidation of affected tags
*/

uses(RefreshDatabase::class);

const REVALIDATE_SECRET = 'a-test-secret-that-is-long-enough';

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);

    config([
        'revalidation.url' => 'http://web.test/api/revalidate',
        'revalidation.secret' => REVALIDATE_SECRET,
    ]);

    Http::fake(['web.test/*' => Http::response(['revalidated' => []])]);
});

/** @return list<list<string>> the tag lists sent, in order */
function sentTags(): array
{
    return Http::recorded()
        ->map(fn (array $pair): array => json_decode($pair[0]->body(), true)['tags'])
        ->values()
        ->all();
}

/** @return array<string, list<array<string, string>>> */
function revalidationSheet(string $path): array
{
    return [
        'sources.csv' => [
            ['source_ref' => 'rv-results', 'source_type_key' => 'media', 'title' => 'Results (example)', 'url' => 'https://news.example/rv', 'retrieved_at' => '2026-10-01'],
        ],
        'persons.csv' => [
            ['person_ref' => 'rv-p1', 'full_name_en' => 'Example Member', 'source_ref' => 'rv-results'],
        ],
        'office_holdings.csv' => [
            ['person_ref' => 'rv-p1', 'position_key' => 'ward_chair', 'constituency_path' => "{$path}/1", 'start_date' => '2022-05-30', 'is_independent' => 'true', 'source_ref' => 'rv-results'],
        ],
    ];
}

it('signs the tags exactly as the web route verifies them', function (): void {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));

    DispatchRevalidation::dispatchSync(['place:koshi/sunsari/example', 'index']);

    Carbon::setTestNow();

    Http::assertSent(function (Request $request): bool {
        $timestamp = $request->header('X-HW-Timestamp')[0];
        $signature = $request->header('X-HW-Signature')[0];

        return $request->url() === 'http://web.test/api/revalidate'
            && $request->body() === '{"tags":["place:koshi/sunsari/example","index"]}'
            && $signature === hash_hmac('sha256', $timestamp.'.'.$request->body(), REVALIDATE_SECRET);
    });

    // The same known-answer vector apps/web/src/lib/__tests__/revalidate.test.ts
    // pins: if either side changes what it signs, both suites fail.
    expect(DispatchRevalidation::sign('1800000000', '{"tags":["place:koshi/sunsari/example","index"]}', REVALIDATE_SECRET))
        ->toBe('2f55c0578b4423cd637598ef922fe15333525560c91d37ae9d6099e235582731');
});

it('sends nothing when revalidation is not configured, or the secret is the placeholder', function (): void {
    config(['revalidation.url' => '']);
    DispatchRevalidation::dispatchSync(['index']);

    config(['revalidation.url' => 'http://web.test/api/revalidate', 'revalidation.secret' => 'change-me-in-every-environment']);
    DispatchRevalidation::dispatchSync(['index']);

    Http::assertNothingSent();
});

it('fails loudly when the web tier refuses, so the queue retries', function (): void {
    // A host of its own: the stub from beforeEach would otherwise match first.
    config(['revalidation.url' => 'http://refusing.test/api/revalidate']);
    Http::fake(['refusing.test/*' => Http::response(['error' => 'unauthorised'], 401)]);

    expect(fn () => DispatchRevalidation::dispatchSync(['index']))->toThrow(RequestException::class);
});

it('refreshes the municipality and the lists after its import, and nothing on a dry run or a re-run', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        $directory = sheet(revalidationSheet($path));

        runImport($directory, ['--tenant' => $path, '--dry-run' => true]);
        Http::assertNothingSent();

        runImport($directory, ['--tenant' => $path]);

        // One signal from the import itself, one from the outbox drain it
        // queued; repeats are harmless by design.
        expect(sentTags())->each->toEqualCanonicalizing(["place:{$path}", 'index']);

        $sent = count(Http::recorded());
        runImport($directory, ['--tenant' => $path]);

        expect(Http::recorded())->toHaveCount($sent);
    }, tenantWithPublishedWards());
});

it('refreshes every page after a central import', function (): void {
    runImport(sheet(['persons.csv' => [['person_ref' => 'rv-central', 'full_name_en' => 'Central Person']]]));

    expect(sentTags())->toBe([['public']]);
});

it('refreshes the municipality when a term ends outside an import', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $path = tenantPath($tenant);
        runImport(sheet(revalidationSheet($path)), ['--tenant' => $path]);
        $before = count(Http::recorded());

        inTenant($tenant, function (): void {
            app(EndOfficeHolding::class)->handle(
                OfficeHolding::query()->where('person_id', ImportContext::id('person', 'rv-p1'))->firstOrFail(),
                Carbon::parse('2025-01-15'),
                HoldingEndReason::Resignation,
            );
        });

        DispatchTenantOutbox::dispatchSync($tenant->id);

        expect(array_slice(sentTags(), $before))->toBe([["place:{$path}", 'index']]);
    }, tenantWithPublishedWards());
});

it('refreshes every page from the command, as the post-deploy step does', function (): void {
    $this->artisan('hw:revalidate')->assertSuccessful();
    $this->artisan('hw:revalidate', ['tags' => ['index']])->assertSuccessful();

    expect(sentTags())->toBe([['public'], ['index']]);
});

it('queues the refresh instead of sending it when asked, as container boot does', function (): void {
    Queue::fake();

    $this->artisan('hw:revalidate', ['--queue' => true])->assertSuccessful();

    Queue::assertPushed(DispatchRevalidation::class, fn (DispatchRevalidation $job): bool => $job->tags === ['public']);
    Http::assertNothingSent();
});

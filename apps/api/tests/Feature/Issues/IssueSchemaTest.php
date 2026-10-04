<?php

declare(strict_types=1);

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Issues\Enums\LifecycleStatus;
use App\Modules\Issues\Enums\ModerationState;
use App\Modules\Issues\Enums\ReporterRelationship;
use App\Modules\Issues\Models\Issue;
use App\Modules\Issues\Models\IssueStatusEvent;
use App\Modules\Issues\Models\TenantIssueCategory;
use App\Modules\Issues\Support\IssuePublicId;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\IssueCategorySeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The tenant issue schema (HW-E11-F01-T01, docs/05 §6). Acceptance criteria:
|   - schema per 05 §6 and 12 §12.3 with moderation_state and lifecycle_status separate
|   - public_id prefixed with tenant key and collision-safe
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(IssueCategorySeeder::class);
});

/**
 * Column values for one valid report, before any override.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function issueRow(Tenant $tenant, array $overrides = []): array
{
    $ward = TenantAdminUnit::query()->where('level', AdminLevel::Ward->value)->orderBy('ward_number')->firstOrFail();

    return [
        'public_id' => IssuePublicId::generate($tenant->tenant_key),
        'ward_id' => $ward->id,
        'category_key' => 'drainage',
        'title' => 'Blocked drain by the school',
        'description' => 'The drain has overflowed onto the road since Tuesday.',
        'language' => 'en',
        'reporter_relationship' => ReporterRelationship::PermanentAddress->value,
        'client_fingerprint' => hash('sha256', 'test'),
        ...$overrides,
    ];
}

/** @param array<string, mixed> $overrides */
function newIssue(Tenant $tenant, array $overrides = []): Issue
{
    return Issue::query()->create(issueRow($tenant, $overrides));
}

it('replicates the category catalogue into every tenant', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $keys = inTenant($tenant, fn () => TenantIssueCategory::query()->active()->pluck('key')->all());

        expect($keys)->toHaveCount(14)
            ->and($keys[0])->toBe('roads')
            ->and(end($keys))->toBe('other');
    }, tenantWithPublishedWards());
});

it('files a report pending moderation and open, two states that move separately', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        inTenant($tenant, function () use ($tenant): void {
            $issue = newIssue($tenant)->refresh();

            expect($issue->moderation_state)->toBe(ModerationState::Pending)
                ->and($issue->lifecycle_status)->toBe(LifecycleStatus::Open)
                ->and($issue->toArray())->not->toHaveKeys(['reporter_user_id', 'reporter_relationship', 'client_fingerprint']);

            // A rejected report about a problem that was fixed anyway.
            $issue->forceFill(['moderation_state' => ModerationState::Rejected, 'lifecycle_status' => LifecycleStatus::Resolved])->save();

            expect($issue->refresh()->moderation_state)->toBe(ModerationState::Rejected)
                ->and($issue->lifecycle_status)->toBe(LifecycleStatus::Resolved);
        });
    }, tenantWithPublishedWards());
});

it('enforces the rules in the database, not only in the application', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        inTenant($tenant, function () use ($tenant): void {
            $localLevel = TenantAdminUnit::query()->where('level', AdminLevel::LocalLevel->value)->firstOrFail();
            $prefix = substr($tenant->tenant_key, 0, 4);

            $refused = [
                'a municipality is not a ward' => ['ward_id' => $localLevel->id],
                'unknown category' => ['category_key' => 'potholes'],
                'title too short' => ['title' => 'Bad'],
                'description too short' => ['description' => 'Broken.'],
                'approved but never published' => ['moderation_state' => 'approved'],
                'unknown relationship' => ['reporter_relationship' => 'neighbour'],
                'public id without prefix' => ['public_id' => 'ABCDEFGHJK'],
                'public id with ambiguous letters' => ['public_id' => "{$prefix}-ABCDEFGHIL"],
            ];

            // Straight to the table, past the model's casts: the point is
            // what the database itself refuses.
            foreach ($refused as $overrides) {
                expectRejectedByTenantDatabase(fn () => DB::connection('tenant')->table('issues')->insert([
                    'id' => (string) Str::uuid(),
                    ...issueRow($tenant, $overrides),
                ]));
            }

            expect(newIssue($tenant, ['moderation_state' => 'approved', 'published_at' => now()])->exists)->toBeTrue();
        });
    }, tenantWithPublishedWards());
});

it('refuses a second report with the same public id', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        inTenant($tenant, function () use ($tenant): void {
            $first = newIssue($tenant);

            expectRejectedByTenantDatabase(fn () => newIssue($tenant, ['public_id' => $first->public_id]));
        });
    }, tenantWithPublishedWards());
});

it('keeps the status timeline append-only', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        inTenant($tenant, function () use ($tenant): void {
            $issue = newIssue($tenant);
            $event = IssueStatusEvent::query()->create([
                'issue_id' => $issue->id,
                'from_status' => LifecycleStatus::Open,
                'to_status' => LifecycleStatus::Acknowledged,
                'note_en' => 'The ward office has seen this.',
            ]);

            expect($issue->statusEvents()->pluck('to_status')->all())->toBe([LifecycleStatus::Acknowledged]);

            expectRejectedByTenantDatabase(fn () => $event->forceFill(['note_en' => 'Edited'])->save());
            expectRejectedByTenantDatabase(fn () => $event->delete());
            expectRejectedByTenantDatabase(fn () => IssueStatusEvent::query()->create([
                'issue_id' => $issue->id, 'from_status' => 'open', 'to_status' => 'open',
            ]));
        });
    }, tenantWithPublishedWards());
});

it('gives every tenant a distinct public id prefix', function (): void {
    $keys = Tenant::factory()->count(20)->create()->pluck('tenant_key');

    expect($keys->map(fn (string $key): string => substr($key, 0, 4))->unique())->toHaveCount(20);

    // A key chosen by hand that shares an existing prefix is refused too.
    $taken = (string) $keys->first();
    $clash = substr($taken, 0, 4).(substr($taken, 4) === '0000' ? '0001' : '0000');

    expectRejectedByDatabase(fn () => Tenant::factory()->create(['tenant_key' => $clash]));
});

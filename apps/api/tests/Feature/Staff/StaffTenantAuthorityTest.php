<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Staff\Actions\GrantTenantRole;
use App\Modules\Staff\Actions\RevokeTenantRole;
use App\Modules\Staff\Enums\TenantRole;
use App\Modules\Staff\Exceptions\StaffAuthorityException;
use App\Modules\Staff\Models\StaffMembership;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| Staff identity and per-municipality authority (HW-E13-F01-T01, docs/12 §11.5).
| Acceptance criteria:
|   - membership roles moderator/verifier/data_editor/viewer per tenant
|   - moderator of tenant A gets 403/404 on tenant B
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A stand-in for the staff endpoints that arrive with moderation
    // (HW-E14-F01-T03): signed in on the staff guard, authorised per tenant.
    Route::middleware(['web', 'auth:staff'])->get('/_test/staff/{tenantKey}/queue', function (string $tenantKey) {
        Gate::forUser(auth('staff')->user())->authorize('moderate', Tenant::query()->where('tenant_key', $tenantKey)->firstOrFail());

        return response()->noContent();
    });
});

/**
 * An operator admin and two municipalities, nobody a member of either.
 *
 * @return array{0: StaffUser, 1: Tenant, 2: Tenant} admin, Koshara, Sonapur
 */
function staffWorld(): array
{
    return [StaffUser::factory()->operatorAdmin()->create(), Tenant::factory()->create(), Tenant::factory()->create()];
}

function grant(StaffUser $admin, StaffUser $staff, Tenant $tenant, TenantRole $role): StaffMembership
{
    return app(GrantTenantRole::class)->handle($admin, $staff, $tenant, $role);
}

it('confines a moderator to the municipality they moderate', function (): void {
    [$admin, $koshara, $sonapur] = staffWorld();

    $moderator = StaffUser::factory()->create();
    grant($admin, $moderator, $koshara, TenantRole::Moderator);

    $this->actingAs($moderator, 'staff')->get("/_test/staff/{$koshara->tenant_key}/queue")->assertNoContent();

    // No membership in Sonapur: the queue does not exist, as far as they know.
    $this->actingAs($moderator, 'staff')->get("/_test/staff/{$sonapur->tenant_key}/queue")->assertNotFound();
});

it('answers 403 to a member of the municipality in another role', function (): void {
    [$admin, $koshara, $sonapur] = staffWorld();

    $viewer = StaffUser::factory()->create();
    grant($admin, $viewer, $sonapur, TenantRole::Viewer);

    $this->actingAs($viewer, 'staff')->get("/_test/staff/{$sonapur->tenant_key}/queue")->assertForbidden();
});

it('lets each role do its own job and only that', function (TenantRole $role, string $allowed): void {
    [$admin, $koshara, $sonapur] = staffWorld();

    $staff = StaffUser::factory()->create();
    grant($admin, $staff, $koshara, $role);

    foreach (['view', 'moderate', 'verify', 'editData', 'manage'] as $ability) {
        $expected = $ability === 'view' || $ability === $allowed;

        expect(Gate::forUser($staff)->allows($ability, $koshara))->toBe($expected, "{$role->value} → {$ability}");
    }
})->with([
    'moderator' => [TenantRole::Moderator, 'moderate'],
    'verifier' => [TenantRole::Verifier, 'verify'],
    'data editor' => [TenantRole::DataEditor, 'editData'],
    'viewer' => [TenantRole::Viewer, 'view'],
]);

it('gives the operator admin every municipality, and an inactive account nothing', function (): void {
    [$admin, $koshara, $sonapur] = staffWorld();

    expect(Gate::forUser($admin)->allows('moderate', $sonapur))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('manage', $koshara))->toBeTrue();

    $former = StaffUser::factory()->create();
    grant($admin, $former, $koshara, TenantRole::Moderator);
    $former->forceFill(['is_active' => false])->save();

    expect(Gate::forUser($former->refresh())->inspect('view', $koshara)->status())->toBe(404);
});

it('changes a role by revoking and granting, keeping the history, and audits both', function (): void {
    [$admin, $koshara, $sonapur] = staffWorld();

    $staff = StaffUser::factory()->create();
    $first = grant($admin, $staff, $koshara, TenantRole::Viewer);

    expect(grant($admin, $staff, $koshara, TenantRole::Viewer)->id)->toBe($first->id);

    grant($admin, $staff, $koshara, TenantRole::Moderator);

    $rows = StaffMembership::query()->where('staff_user_id', $staff->id)->orderBy('granted_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->revoked_at)->not->toBeNull()
        ->and($rows[0]->revoked_by)->toBe($admin->id)
        ->and($rows[1]->role)->toBe(TenantRole::Moderator)
        ->and($staff->roleIn($koshara))->toBe(TenantRole::Moderator)
        ->and(AuditEvent::query()->where('actor_id', $admin->id)->pluck('action')->all())
        ->toBe(['staff_membership.granted', 'staff_membership.revoked', 'staff_membership.granted']);

    app(RevokeTenantRole::class)->handle($admin, $rows[1]);

    expect(Gate::forUser($staff)->inspect('view', $koshara)->status())->toBe(404);
});

it('lets only an operator admin hand out authority', function (): void {
    [$admin, $koshara, $sonapur] = staffWorld();

    $moderator = StaffUser::factory()->create();
    grant($admin, $moderator, $koshara, TenantRole::Moderator);

    expect(fn () => app(GrantTenantRole::class)->handle($moderator, StaffUser::factory()->create(), $koshara, TenantRole::Moderator))
        ->toThrow(StaffAuthorityException::class)
        ->and(fn () => app(GrantTenantRole::class)->handle($admin, StaffUser::factory()->inactive()->create(), $koshara, TenantRole::Viewer))
        ->toThrow(StaffAuthorityException::class);
});

it('keeps memberships in the database as a record: one live row, never edited or deleted', function (): void {
    [$admin, $koshara, $sonapur] = staffWorld();

    $staff = StaffUser::factory()->create();
    $membership = grant($admin, $staff, $koshara, TenantRole::Viewer);

    expectRejectedByDatabase(fn () => StaffMembership::query()->create([
        'staff_user_id' => $staff->id, 'tenant_id' => $koshara->id, 'role' => 'moderator',
    ]));
    expectRejectedByDatabase(fn () => $membership->forceFill(['role' => 'moderator'])->save());
    expectRejectedByDatabase(fn () => $membership->delete());
    // Past the model's enum cast, to prove the database's own check.
    expectRejectedByDatabase(fn () => DB::connection('central')->table('staff_memberships')->insert([
        'id' => (string) Str::uuid(), 'staff_user_id' => $staff->id, 'tenant_id' => $sonapur->id, 'role' => 'owner',
    ]));
    expectRejectedByDatabase(fn () => StaffUser::factory()->create(['email' => strtoupper($staff->email)]));
});

it('keeps staff secrets out of serialised output', function (): void {
    [$admin, $koshara, $sonapur] = staffWorld();

    $staff = StaffUser::factory()->create();
    $staff->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

    expect($staff->refresh()->toArray())->not->toHaveKeys(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token']);
});

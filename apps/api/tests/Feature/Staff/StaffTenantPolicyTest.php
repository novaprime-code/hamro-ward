<?php

declare(strict_types=1);

use App\Modules\Staff\Enums\StaffPermission;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\Role;
use App\Modules\Staff\Models\StaffMembership;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Staff\Policies\StaffTenantPolicy;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\StaffRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * HW-E13-F01-T01 — staff authorization (docs/12 §11.5, docs/06 §12).
 *
 * The acceptance criterion is one sentence: **a moderator of tenant A gets
 * nothing in tenant B.** Everything else here exists to stop that from being
 * true by accident — a role that happens to be absent, a check that happens to
 * deny — rather than by construction.
 */
beforeEach(function (): void {
    $this->seed(StaffRoleSeeder::class);
    $this->policy = app(StaffTenantPolicy::class);
});

function moderatorOf(Tenant $tenant, StaffRole $role = StaffRole::Moderator): StaffUser
{
    $staff = StaffUser::factory()->withTwoFactor()->create();
    StaffMembership::factory()->for_($staff, $tenant)->role($role)->create();

    return $staff;
}

// ---------------------------------------------------------------------------
// The acceptance criterion
// ---------------------------------------------------------------------------

it('gives a moderator of one municipality nothing in another', function (): void {
    $koshara = Tenant::factory()->create();
    $sonapur = Tenant::factory()->create();

    $staff = moderatorOf($koshara);

    expect($this->policy->allows($staff, StaffPermission::IssuesModerate, $koshara))->toBeTrue()
        ->and($this->policy->allows($staff, StaffPermission::IssuesModerate, $sonapur))->toBeFalse();

    // Not even to look. mayEnter is the check InitializeTenancyForStaff makes
    // BEFORE switching databases (docs/12 §16), so this is the one that stops
    // another municipality's data being opened at all.
    expect($this->policy->mayEnter($staff, $koshara))->toBeTrue()
        ->and($this->policy->mayEnter($staff, $sonapur))->toBeFalse();

    expect($staff->roleIn($koshara))->toBe(StaffRole::Moderator)
        ->and($staff->roleIn($sonapur))->toBeNull();
});

it('refuses every permission in a municipality the person is not a member of', function (): void {
    $theirs = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $staff = moderatorOf($theirs);

    foreach (StaffPermission::cases() as $permission) {
        expect($this->policy->allows($staff, $permission, $other))
            ->toBeFalse("{$permission->value} leaked into a municipality with no membership");
    }
});

// ---------------------------------------------------------------------------
// The permission grid (docs/06 §12)
// ---------------------------------------------------------------------------

it('grants each membership exactly the permissions the grid lists', function (StaffRole $role, array $expected): void {
    $tenant = Tenant::factory()->create();
    $staff = moderatorOf($tenant, $role);

    foreach (StaffPermission::cases() as $permission) {
        $shouldHave = in_array($permission->value, $expected, strict: true);

        expect($this->policy->allows($staff, $permission, $tenant))->toBe(
            $shouldHave,
            "{$role->value} and {$permission->value}: expected ".($shouldHave ? 'allowed' : 'denied'),
        );
    }
})->with([
    'moderator' => [StaffRole::Moderator, [
        'queue.view', 'issues.moderate', 'corrections.moderate',
        'moderation.second_approve', 'issues.update_lifecycle',
    ]],
    'verifier' => [StaffRole::Verifier, [
        'queue.view', 'moderation.second_approve', 'sources.verify',
    ]],
    'data editor' => [StaffRole::DataEditor, [
        'queue.view', 'data.import', 'data.edit',
    ]],
    'viewer' => [StaffRole::Viewer, [
        'queue.view',
    ]],
]);

it('keeps the operator-admin-only permissions out of every membership', function (): void {
    $tenant = Tenant::factory()->create();

    /*
     * staff.manage, affiliations.view, settings.manage and audit.view.
     *
     * affiliations.view is the one that matters most: a declaration states
     * someone's political affiliation, kept encrypted and readable by the
     * operator admin alone (docs/12 §15). A moderator who could read their
     * colleagues' declarations would turn a conflict-of-interest control into
     * a political register of the volunteers.
     */
    foreach (StaffRole::cases() as $role) {
        $staff = moderatorOf($tenant, $role);

        foreach (StaffRole::operatorAdminOnly() as $permission) {
            expect($this->policy->allows($staff, $permission, $tenant))
                ->toBeFalse("{$role->value} should not hold {$permission->value}");
        }
    }
});

// ---------------------------------------------------------------------------
// The global role
// ---------------------------------------------------------------------------

it('lets an operator admin into every municipality without a membership', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $admin = StaffUser::factory()->withTwoFactor()->create();
    $admin->assignRole(StaffRole::OPERATOR_ADMIN);

    expect($admin->isOperatorAdmin())->toBeTrue()
        // No membership rows at all — they bypass memberships rather than
        // holding one everywhere, so roleIn() honestly reports nothing.
        ->and($admin->activeMemberships()->count())->toBe(0)
        ->and($admin->roleIn($a))->toBeNull();

    foreach ([$a, $b] as $tenant) {
        foreach (StaffPermission::cases() as $permission) {
            expect($this->policy->allows($admin, $permission, $tenant))->toBeTrue();
        }
    }
});

it('keeps the four membership roles out of the roles table', function (): void {
    /*
     * The thing that must never happen. A global `moderator` role would grant
     * moderation in all 753 local levels at once, which is the exact failure
     * the membership design exists to prevent (docs/12 §11.5). The seeder
     * creates one role; this is what notices if that ever changes.
     */
    expect(Role::query()->pluck('name')->all())->toBe([StaffRole::OPERATOR_ADMIN]);
});

it('does not let a citizen guard hold a staff role', function (): void {
    // spatie keys roles by guard, so a role created for `staff` is invisible
    // to the `web` guard. A citizen must never be able to hold one.
    expect(Role::query()->where('name', StaffRole::OPERATOR_ADMIN)->value('guard_name'))
        ->toBe(StaffRole::GUARD);
});

// ---------------------------------------------------------------------------
// Account state
// ---------------------------------------------------------------------------

it('refuses a staff member who has not confirmed two-factor', function (): void {
    $tenant = Tenant::factory()->create();

    // Two-factor is mandatory for staff (docs/12 §11.1), and it is checked on
    // every authorization rather than only at login — otherwise a session
    // opened before enrolment would keep working.
    $unenrolled = StaffUser::factory()->create();
    StaffMembership::factory()->for_($unenrolled, $tenant)->create();

    $pending = StaffUser::factory()->twoFactorPending()->create();
    StaffMembership::factory()->for_($pending, $tenant)->create();

    expect($this->policy->allows($unenrolled, StaffPermission::QueueView, $tenant))->toBeFalse()
        // A secret written while the QR code was on screen is not enrolment.
        ->and($this->policy->allows($pending, StaffPermission::QueueView, $tenant))->toBeFalse();
});

it('refuses a deactivated or locked staff member', function (): void {
    $tenant = Tenant::factory()->create();

    $left = StaffUser::factory()->withTwoFactor()->inactive()->create();
    StaffMembership::factory()->for_($left, $tenant)->create();

    $locked = StaffUser::factory()->withTwoFactor()->locked()->create();
    StaffMembership::factory()->for_($locked, $tenant)->create();

    expect($this->policy->allows($left, StaffPermission::QueueView, $tenant))->toBeFalse()
        ->and($this->policy->allows($locked, StaffPermission::QueueView, $tenant))->toBeFalse();
});

it('refuses an operator admin who has not confirmed two-factor', function (): void {
    // The global role is not an exemption from the rule that matters most on
    // the host that holds moderation.
    $tenant = Tenant::factory()->create();
    $admin = StaffUser::factory()->create();
    $admin->assignRole(StaffRole::OPERATOR_ADMIN);

    expect($this->policy->allows($admin, StaffPermission::SettingsManage, $tenant))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Revocation
// ---------------------------------------------------------------------------

it('stops granting anything once a membership is revoked', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = StaffUser::factory()->withTwoFactor()->create();

    $membership = StaffMembership::factory()->for_($staff, $tenant)->create();
    expect($this->policy->allows($staff, StaffPermission::IssuesModerate, $tenant))->toBeTrue();

    $membership->forceFill(['revoked_at' => now()])->save();

    expect($staff->refresh()->roleIn($tenant))->toBeNull()
        ->and($this->policy->allows($staff, StaffPermission::IssuesModerate, $tenant))->toBeFalse()
        // The row survives: who could moderate when is part of the audit
        // record (docs/03 FR-AUD-01).
        ->and($staff->memberships()->count())->toBe(1);
});

it('allows a revoked membership to be granted again', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = StaffUser::factory()->withTwoFactor()->create();

    StaffMembership::factory()->for_($staff, $tenant)->revoked()->create();
    StaffMembership::factory()->for_($staff, $tenant)->role(StaffRole::Verifier)->create();

    // Two rows, one live. The partial unique index permits exactly this.
    expect($staff->memberships()->count())->toBe(2)
        ->and($staff->roleIn($tenant))->toBe(StaffRole::Verifier)
        ->and($this->policy->allows($staff, StaffPermission::SourcesVerify, $tenant))->toBeTrue()
        ->and($this->policy->allows($staff, StaffPermission::IssuesModerate, $tenant))->toBeFalse();
});

it('refuses two live memberships for one person in one municipality', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = StaffUser::factory()->withTwoFactor()->create();

    StaffMembership::factory()->for_($staff, $tenant)->create();

    /*
     * Enforced by the database, not by the application. Two live memberships
     * would make "what is their role here" ambiguous, and a permission check
     * is the wrong place to be resolving ambiguity.
     */
    expectRejectedByDatabase(
        fn () => StaffMembership::factory()->for_($staff, $tenant)->role(StaffRole::Viewer)->create(),
    );
});

it('refuses a role the grid does not define', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = StaffUser::factory()->withTwoFactor()->create();

    expectRejectedByDatabase(fn () => DB::connection('central')->table('staff_memberships')->insert([
        'id' => Str::uuid()->toString(),
        'staff_user_id' => $staff->id,
        'tenant_id' => $tenant->id,
        'role' => 'superuser',
        'granted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

// ---------------------------------------------------------------------------
// Tenant state (docs/12 §2)
// ---------------------------------------------------------------------------

it('makes a suspended municipality read-only for members', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = moderatorOf($tenant);

    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    // The queue stays visible while whatever caused the suspension is sorted
    // out, but no decision can be taken in it.
    expect($this->policy->allows($staff, StaffPermission::QueueView, $tenant->refresh()))->toBeTrue()
        ->and($this->policy->allows($staff, StaffPermission::IssuesModerate, $tenant))->toBeFalse()
        ->and($this->policy->allows($staff, StaffPermission::IssuesUpdateLifecycle, $tenant))->toBeFalse();
});

it('keeps members out of a municipality that is provisioning or in maintenance', function (string $status): void {
    $tenant = Tenant::factory()->create();
    $staff = moderatorOf($tenant);

    $tenant->forceFill(['status' => $status])->save();

    // The schema may be half-migrated. A moderator approving an issue against
    // a database mid-migration is how a half-written record becomes a
    // published fact.
    expect($this->policy->mayEnter($staff, $tenant->refresh()))->toBeFalse();
})->with(['provisioning', 'maintenance']);

it('keeps everyone, including operator admins, out of an archived municipality', function (): void {
    $tenant = Tenant::factory()->create();
    $staff = moderatorOf($tenant);

    $admin = StaffUser::factory()->withTwoFactor()->create();
    $admin->assignRole(StaffRole::OPERATOR_ADMIN);

    $tenant->forceFill(['status' => TenantStatus::Archived])->save();

    // Archived means the database has been dumped and dropped. There is
    // nothing left to authorize access to, so allowing it would only produce
    // a connection error dressed as an authorization success.
    expect($this->policy->mayEnter($staff, $tenant->refresh()))->toBeFalse()
        ->and($this->policy->mayEnter($admin, $tenant))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The Gate surface controllers will use
// ---------------------------------------------------------------------------

it('answers through the Gate with the tenant as the argument', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $staff = moderatorOf($tenant);

    expect(Gate::forUser($staff)->allows('issues.moderate', $tenant))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('issues.moderate', $other))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('sources.verify', $tenant))->toBeFalse();
});

it('denies a Gate check made without naming a municipality', function (): void {
    /*
     * There is no such question as "may they moderate", only "may they
     * moderate here". The convenient answer to the unqualified form — yes,
     * somewhere — is the national moderation right memberships exist to
     * prevent, so the unqualified form denies.
     */
    $tenant = Tenant::factory()->create();
    $staff = moderatorOf($tenant);

    expect(Gate::forUser($staff)->allows('issues.moderate'))->toBeFalse();
});

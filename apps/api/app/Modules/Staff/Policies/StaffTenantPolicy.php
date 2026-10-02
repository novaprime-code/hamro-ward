<?php

declare(strict_types=1);

namespace App\Modules\Staff\Policies;

use App\Modules\Staff\Enums\StaffPermission;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;

/**
 * The one place that answers "may this person do this, in this municipality?"
 * (docs/12 §11.5, docs/06 §12).
 *
 * Every staff endpoint goes through here, and the order of the checks is the
 * security model:
 *
 *   1. the account can authenticate at all      — active, not locked
 *   2. two-factor is confirmed                  — mandatory for staff
 *   3. operator_admin short-circuits            — global role, every tenant
 *   4. the tenant is in a state staff may touch
 *   5. an ACTIVE membership of THAT tenant exists
 *   6. that membership's role carries the permission
 *
 * Nothing here consults the default guard or the current request. The caller
 * passes the staff user and the tenant explicitly, because a policy that reads
 * ambient state is a policy that behaves differently in a queued job than in a
 * request, and the queue is where tenant work happens.
 *
 * **The membership check must happen before any database switch.** docs/12 §16
 * is explicit: `InitializeTenancyForStaff` asks this policy first and only
 * then points the connection at the tenant. Checking afterwards would mean a
 * moderator from another municipality had already opened this one's database
 * by the time they were refused.
 *
 * D-002 recusals and the affiliation-declaration gate are not here yet
 * (HW-E13-F01-T04). They will narrow what this returns, never widen it, and
 * `allows()` is the single place they attach.
 */
final class StaffTenantPolicy
{
    /**
     * Whether the staff member may exercise the permission in the tenant.
     */
    public function allows(StaffUser $staff, StaffPermission $permission, Tenant $tenant): bool
    {
        if (! $staff->canAuthenticate()) {
            return false;
        }

        /*
         * Two-factor is mandatory for staff (docs/12 §11.1), and it is checked
         * here rather than only at login because a session that predates
         * enrolment would otherwise keep working. "Enrolled" means confirmed:
         * a secret written while the QR code was on screen proves nothing.
         */
        if (! $staff->hasConfirmedTwoFactor()) {
            return false;
        }

        if ($staff->isOperatorAdmin()) {
            return $this->tenantIsReachableByOperatorAdmin($tenant);
        }

        if (! $this->tenantIsReachableByMember($tenant, $permission)) {
            return false;
        }

        $role = $staff->roleIn($tenant);

        return $role instanceof StaffRole && $role->grants($permission);
    }

    /**
     * Whether the staff member has any business in this tenant at all — the
     * check `InitializeTenancyForStaff` makes before switching databases.
     */
    public function mayEnter(StaffUser $staff, Tenant $tenant): bool
    {
        return $this->allows($staff, StaffPermission::QueueView, $tenant);
    }

    /**
     * Operator admins reach every tenant except an archived one.
     *
     * Archived means the database has been dumped and dropped (docs/12 §2).
     * There is nothing behind it to authorize access to, so this is not a
     * permission question — allowing it would just produce a connection error
     * wearing the costume of an authorization success.
     */
    private function tenantIsReachableByOperatorAdmin(Tenant $tenant): bool
    {
        return $tenant->status !== TenantStatus::Archived;
    }

    /**
     * Which tenant states a member — anyone who is not an operator admin — may
     * work in (docs/12 §2).
     *
     * `active` only, with one exception: a `suspended` tenant is READ-ONLY for
     * members, so the queue stays visible while the legal hold or whatever
     * prompted the suspension is sorted out. Reading is `queue.view` and
     * `audit.view`; everything else is refused, because a suspension that
     * still allowed moderation decisions would not be a suspension.
     *
     * `provisioning` and `maintenance` are operator-admin only: the schema may
     * be half-migrated, and a moderator approving an issue against a database
     * mid-migration is how a half-written record becomes a published fact.
     */
    private function tenantIsReachableByMember(Tenant $tenant, StaffPermission $permission): bool
    {
        if ($tenant->status === TenantStatus::Active) {
            return true;
        }

        if ($tenant->status === TenantStatus::Suspended) {
            return in_array(
                $permission,
                [StaffPermission::QueueView, StaffPermission::AuditView],
                strict: true,
            );
        }

        return false;
    }
}

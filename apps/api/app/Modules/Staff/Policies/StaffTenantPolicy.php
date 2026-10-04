<?php

declare(strict_types=1);

namespace App\Modules\Staff\Policies;

use App\Modules\Staff\Enums\TenantRole;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Access\Response;

/**
 * What a member of staff may do in a municipality (docs/12 §11.5, FR-STF-02).
 *
 * Authority comes from a live membership in THAT tenant, or from the global
 * operator_admin role. Nothing else: a moderator in one municipality has no
 * rights in the next one, whatever they hold there.
 *
 * Someone with no membership gets a 404, not a 403. A staff member of Koshara
 * probing Sonapur's queue should not learn that Sonapur has one. Someone who
 * is a member but in another role gets a 403: they know the place exists, and
 * the honest answer is "not with this role".
 */
final class StaffTenantPolicy
{
    /** Read the municipality's staff views: any role. */
    public function view(StaffUser $staff, Tenant $tenant): Response
    {
        return $this->allow($staff, $tenant, TenantRole::cases());
    }

    public function moderate(StaffUser $staff, Tenant $tenant): Response
    {
        return $this->allow($staff, $tenant, [TenantRole::Moderator]);
    }

    public function verify(StaffUser $staff, Tenant $tenant): Response
    {
        return $this->allow($staff, $tenant, [TenantRole::Verifier]);
    }

    public function editData(StaffUser $staff, Tenant $tenant): Response
    {
        return $this->allow($staff, $tenant, [TenantRole::DataEditor]);
    }

    /** Grant and revoke memberships, change tenant settings: operator_admin only. */
    public function manage(StaffUser $staff, Tenant $tenant): Response
    {
        if ($staff->isOperatorAdmin()) {
            return Response::allow();
        }

        return $staff->roleIn($tenant) === null ? Response::denyAsNotFound() : Response::deny();
    }

    /**
     * @param  list<TenantRole>  $roles
     */
    private function allow(StaffUser $staff, Tenant $tenant, array $roles): Response
    {
        if ($staff->isOperatorAdmin()) {
            return Response::allow();
        }

        $role = $staff->roleIn($tenant);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, $roles, true) ? Response::allow() : Response::deny();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Staff\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Staff\Enums\TenantRole;
use App\Modules\Staff\Exceptions\StaffAuthorityException;
use App\Modules\Staff\Models\StaffMembership;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Gives a member of staff a role in one municipality (docs/12 §11.5).
 *
 * A change of role is a revocation and a grant, in one transaction, both
 * audited: the old row keeps saying what the person could do until now. The
 * same role granted twice is a no-op, not a second row.
 *
 * Affiliation declarations (D-002) gate the moderator and verifier roles once
 * they exist (HW-E13-F01-T04).
 */
final class GrantTenantRole
{
    public function __construct(
        private readonly RecordAuditEvent $audit,
        private readonly RevokeTenantRole $revoke,
    ) {}

    public function handle(StaffUser $actor, StaffUser $staff, Tenant $tenant, TenantRole $role): StaffMembership
    {
        if (! $actor->isOperatorAdmin()) {
            throw StaffAuthorityException::notOperatorAdmin();
        }

        if (! $staff->is_active) {
            throw StaffAuthorityException::inactive();
        }

        return $staff->getConnection()->transaction(function () use ($actor, $staff, $tenant, $role): StaffMembership {
            $live = $staff->memberships()->live()->where('tenant_id', $tenant->id)->lockForUpdate()->first();

            if ($live?->role === $role) {
                return $live;
            }

            if ($live !== null) {
                $this->revoke->handle($actor, $live);
            }

            $membership = StaffMembership::query()->create([
                'staff_user_id' => $staff->id,
                'tenant_id' => $tenant->id,
                'role' => $role,
                'granted_by' => $actor->id,
                'granted_at' => now(),
            ]);

            $this->audit->central(
                ActorType::Staff,
                'staff_membership.granted',
                'staff_membership',
                $membership->id,
                ['after' => ['staff_user_id' => $staff->id, 'tenant_id' => $tenant->id, 'role' => $role->value]],
                actorId: $actor->id,
            );

            return $membership;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Staff\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Staff\Exceptions\StaffAuthorityException;
use App\Modules\Staff\Models\StaffMembership;
use App\Modules\Staff\Models\StaffUser;

/**
 * Ends a membership. The row stays, stamped with who ended it and when.
 */
final class RevokeTenantRole
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(StaffUser $actor, StaffMembership $membership): StaffMembership
    {
        if (! $actor->isOperatorAdmin()) {
            throw StaffAuthorityException::notOperatorAdmin();
        }

        if ($membership->revoked_at !== null) {
            return $membership;
        }

        return $membership->getConnection()->transaction(function () use ($actor, $membership): StaffMembership {
            $membership->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();

            $this->audit->central(
                ActorType::Staff,
                'staff_membership.revoked',
                'staff_membership',
                $membership->id,
                ['before' => ['role' => $membership->role->value, 'tenant_id' => $membership->tenant_id]],
                actorId: $actor->id,
            );

            return $membership;
        });
    }
}

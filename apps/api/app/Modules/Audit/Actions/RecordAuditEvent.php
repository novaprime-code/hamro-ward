<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Models\BaseAuditEvent;
use App\Modules\Audit\Models\TenantAuditEvent;

/**
 * Appends an event to the audit trail of the database the change was made in
 * (docs/05 §9.1).
 *
 * The caller says which database, because the caller knows: a change to a
 * person is central, a change to an office holding belongs to the tenant
 * whose database it was written to. Guessing from the subject type would put
 * a tenant's history in central the first time a new subject type appeared.
 *
 * Writing inside the caller's transaction is the point. An event for a change
 * that was rolled back is a false record; a change committed without its
 * event is an unaudited one. Sharing the transaction rules out both.
 */
final class RecordAuditEvent
{
    /**
     * @param  array<string, mixed>|null  $changes  {"before": {...}, "after": {...}}, or a summary for run-level events
     */
    public function central(
        ActorType $actor,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $changes = null,
        ?string $requestId = null,
        ?string $actorId = null,
    ): AuditEvent {
        /** @var AuditEvent */
        return $this->write(new AuditEvent, $actor, $action, $subjectType, $subjectId, $changes, $requestId, $actorId);
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function tenant(
        ActorType $actor,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $changes = null,
        ?string $requestId = null,
        ?string $actorId = null,
    ): TenantAuditEvent {
        /** @var TenantAuditEvent */
        return $this->write(new TenantAuditEvent, $actor, $action, $subjectType, $subjectId, $changes, $requestId, $actorId);
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    private function write(
        BaseAuditEvent $event,
        ActorType $actor,
        string $action,
        ?string $subjectType,
        ?string $subjectId,
        ?array $changes,
        ?string $requestId,
        ?string $actorId,
    ): BaseAuditEvent {
        $event->forceFill([
            'actor_type' => $actor->value,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'changes' => $changes,
            'request_id' => $requestId,
        ])->save();

        return $event;
    }
}

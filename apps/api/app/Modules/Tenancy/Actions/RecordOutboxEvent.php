<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\OutboxEvent;

/**
 * Notes, inside the current tenant transaction, that a change needs to reach
 * the central indexes (docs/12 §4.3).
 *
 * The caller's transaction is the point. Recorded outside it, an event could
 * describe a change that was then rolled back, or a committed change could
 * lose its event to a crash between the two writes.
 */
final class RecordOutboxEvent
{
    public const HOLDING_CHANGED = 'office_holding.changed';

    /** @param  array<string, mixed>  $payload */
    public function handle(string $eventType, string $subjectType, string $subjectId, array $payload = []): OutboxEvent
    {
        return OutboxEvent::query()->create([
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload,
        ]);
    }

    /** A holding was created, changed or ended; whose person page that touches. */
    public function holdingChanged(string $holdingId, string $personId): OutboxEvent
    {
        return $this->handle(self::HOLDING_CHANGED, 'office_holding', $holdingId, ['person_id' => $personId]);
    }
}

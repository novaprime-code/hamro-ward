<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Audit\Enums\ActorType;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the append-only audit trail (docs/05 §9.1, FR-AUD-01).
 *
 * The database refuses UPDATE and DELETE on this table for every role, so the
 * model only ever inserts. occurred_at and id are the database's to set.
 *
 * @property int $id
 * @property ActorType $actor_type
 * @property string $action
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property ?array<string, mixed> $changes
 * @property ?string $request_id
 */
abstract class BaseAuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'audit_events';

    protected $guarded = ['id', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'changes' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}

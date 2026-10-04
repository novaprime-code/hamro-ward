<?php

declare(strict_types=1);

namespace App\Modules\Issues\Models;

use App\Modules\Issues\Enums\LifecycleStatus;
use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step on a report's public timeline (FR-ISS-08). Append-only: the
 * database refuses UPDATE, DELETE and TRUNCATE.
 *
 * @property string $id
 * @property string $issue_id
 * @property LifecycleStatus|null $from_status
 * @property LifecycleStatus $to_status
 * @property string|null $note_ne
 * @property string|null $note_en
 * @property string|null $source_id
 * @property string|null $actor_staff_id
 * @property Carbon $created_at
 */
final class IssueStatusEvent extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'issue_status_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => LifecycleStatus::class,
            'to_status' => LifecycleStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}

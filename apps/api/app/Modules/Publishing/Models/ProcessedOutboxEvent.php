<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * An outbox event already applied to the central indexes. Its presence is
 * what makes applying an event twice a no-op (docs/12 §4.3).
 *
 * @property string $event_id
 * @property string $tenant_id
 * @property string $event_type
 */
final class ProcessedOutboxEvent extends Model
{
    use UsesCentralConnection;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'processed_outbox_events';

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    protected $guarded = [];
}

<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A change this municipality owes the central indexes (docs/12 §4.3).
 *
 * @property string $id
 * @property string $event_type
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string, mixed>|null $payload
 * @property Carbon $created_at
 * @property Carbon|null $processed_at
 */
final class OutboxEvent extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'outbox_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('processed_at')->orderBy('created_at')->orderBy('id');
    }
}

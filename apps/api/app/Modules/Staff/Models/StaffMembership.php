<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use App\Modules\Staff\Enums\TenantRole;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One role for one person in one municipality (docs/12 §11.5). Revoked, never
 * edited or deleted — the database enforces both.
 *
 * @property string $id
 * @property string $staff_user_id
 * @property string $tenant_id
 * @property TenantRole $role
 * @property string|null $granted_by
 * @property Carbon $granted_at
 * @property string|null $revoked_by
 * @property Carbon|null $revoked_at
 */
final class StaffMembership extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    public $timestamps = false;

    protected $table = 'staff_memberships';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<StaffUser, $this> */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @param  Builder<StaffMembership>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}

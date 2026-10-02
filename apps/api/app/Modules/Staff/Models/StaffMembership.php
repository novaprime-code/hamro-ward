<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\StaffMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One staff member's access to one municipality (docs/12 §11.5, docs/05 §9).
 *
 * Central, because a moderator may cover two or three municipalities and the
 * D-002 affiliation checks have to span all of them.
 *
 * **Revocation is a timestamp, never a delete.** Who could moderate which
 * municipality, and between which dates, is part of the audit record
 * (docs/03 FR-AUD-01): a deleted row cannot answer "who had access when this
 * decision was made". A partial unique index allows only one unrevoked row per
 * person per tenant, so the history can be as long as it likes while the
 * question "what is their role here, now" stays unambiguous.
 *
 * @property string $id
 * @property string $staff_user_id
 * @property string $tenant_id
 * @property StaffRole $role
 * @property string|null $granted_by
 * @property Carbon $granted_at
 * @property Carbon|null $revoked_at
 */
final class StaffMembership extends Model
{
    /** @use HasFactory<StaffMembershipFactory> */
    use HasFactory;

    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'staff_memberships';

    /**
     * Granting and revoking go through the actions that own them, so that
     * every change is audited and the operator-admin check happens in one
     * place (HW-E13-F01-T04). Nothing here is mass-assigned from a request.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'staff_user_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'granted_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param  Builder<StaffMembership>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('staff_memberships.revoked_at');
    }

    protected static function newFactory(): StaffMembershipFactory
    {
        return StaffMembershipFactory::new();
    }
}

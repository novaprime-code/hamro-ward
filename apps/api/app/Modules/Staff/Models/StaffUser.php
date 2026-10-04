<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use App\Modules\Staff\Enums\GlobalRole;
use App\Modules\Staff\Enums\TenantRole;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\StaffUserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * A member of staff (docs/05 §9, docs/12 §11.1). Invite-only, on the admin
 * host and the `staff` guard; never the same row as a citizen account.
 *
 * Two-factor is Fortify's (D-033): it encrypts the secret and the recovery
 * codes itself, which is why those columns carry no cast here.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property GlobalRole|null $global_role
 * @property bool $is_active
 * @property Carbon|null $locked_until
 * @property Carbon|null $two_factor_confirmed_at
 */
final class StaffUser extends Authenticatable
{
    /** @use HasFactory<StaffUserFactory> */
    use HasFactory;

    use HasUuids;
    use TwoFactorAuthenticatable;
    use UsesCentralConnection;

    protected $table = 'staff_users';

    /**
     * Matches the column default, so a model that was just created knows it
     * is active without a refresh — isOperatorAdmin() and roleIn() read it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'global_role' => GlobalRole::class,
            'is_active' => 'boolean',
            'locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'two_factor_confirmed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StaffMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(StaffMembership::class);
    }

    public function isOperatorAdmin(): bool
    {
        return $this->is_active && $this->global_role === GlobalRole::OperatorAdmin;
    }

    /**
     * This person's live role in the tenant, or null. An inactive account has
     * none anywhere, whatever its memberships say.
     */
    public function roleIn(Tenant $tenant): ?TenantRole
    {
        if (! $this->is_active) {
            return null;
        }

        return $this->memberships()
            ->where('tenant_id', $tenant->id)
            ->whereNull('revoked_at')
            ->first()
            ?->role;
    }

    protected static function newFactory(): StaffUserFactory
    {
        return StaffUserFactory::new();
    }
}

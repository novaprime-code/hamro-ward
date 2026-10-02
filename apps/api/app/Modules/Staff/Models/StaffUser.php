<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\StaffUserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * A staff account: a moderator, verifier, data editor, viewer or operator
 * admin (docs/12 §11.1, §11.5, docs/05 §9).
 *
 * **A separate table from `users`, on purpose.** The same person may well be
 * both a citizen who reports potholes and a moderator who reviews them, and
 * when they are, they hold two accounts with two passwords and two sessions on
 * two hosts. Nothing links one to the other. That is not duplication for its
 * own sake — it is what stops a moderator's privileges from following them
 * into the account they file reports from, and what makes "who approved this"
 * a different question from "who reported it".
 *
 * **No email verification and no self-registration.** Staff accounts are
 * created by an operator admin (docs/12 §11.1), so there is no public form
 * that creates one and no unverified state to represent. The admin host does
 * not register Fortify's registration or password-reset routes at all
 * (AuthServiceProvider).
 *
 * **Two-factor is mandatory**, and that is enforced where it has to be — in
 * the middleware that guards every staff endpoint (HW-E13-F01-T03), not here.
 * A model cannot refuse to be read. What this class offers is the honest
 * question `hasConfirmedTwoFactor()` for that middleware to ask.
 *
 * Roles and tenant memberships arrive in HW-E13-F01-T01.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property bool $is_active
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 */
final class StaffUser extends Authenticatable
{
    /** @use HasFactory<StaffUserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUuids;
    use Notifiable;
    use UsesCentralConnection;

    protected $table = 'staff_users';

    /**
     * The spatie guard these roles belong to.
     *
     * Pinned rather than left to `auth.defaults.guard`, which
     * ConfigureAuthForHost rewrites per host and which does not exist at all
     * in a queued job. Without this, a role granted during a request on one
     * host could be invisible to a check made anywhere else.
     */
    protected ?string $guard_name = StaffRole::GUARD;

    /**
     * Name and email only. Everything that decides what this account can do —
     * whether it is active, whether it is locked, its two-factor state — is
     * set by the action that owns that decision, never by a request body.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Two-factor enrolment that the holder actually completed.
     *
     * A secret on its own is not enrolment: Fortify writes one the moment the
     * QR code is generated, and someone who closed the tab at that point has a
     * secret they have never successfully used. Only the confirmation proves
     * they can produce a code, which is the thing that matters on the host that
     * holds moderation.
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Locked out by repeated failed logins (docs/12 §11.5: 5 failures → 15
     * minutes).
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Whether this account may sign in at all.
     *
     * Deactivation is how a staff member who has left is stopped, and it is
     * preferred to deleting the row: their past moderation decisions reference
     * them, and an audit trail pointing at a missing actor is not an audit
     * trail (docs/03 FR-AUD-01).
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active && ! $this->isLocked();
    }

    /**
     * Every membership ever granted, revoked ones included — the audit record
     * (docs/03 FR-AUD-01). Use activeMemberships() to decide anything.
     *
     * @return HasMany<StaffMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(StaffMembership::class, 'staff_user_id');
    }

    /**
     * @return HasMany<StaffMembership, $this>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->whereNull('revoked_at');
    }

    /**
     * The global role (docs/12 §11.5): implies access to every municipality.
     *
     * Checked against the `staff` guard explicitly. spatie keys roles by
     * guard, and the default guard on the admin host is already `staff`, but
     * naming it means this answers the same way from a queued job, where there
     * is no host and no default worth trusting.
     */
    public function isOperatorAdmin(): bool
    {
        return $this->hasRole(StaffRole::OPERATOR_ADMIN, StaffRole::GUARD);
    }

    /**
     * Their role in one municipality, or null if they have none there.
     *
     * Null is the answer for a moderator of a different municipality, and it
     * is the whole point of memberships: there is no global `moderator`, so
     * there is nothing for this to fall back to (docs/12 §11.5).
     *
     * Operator admins get null too. They hold no membership — they bypass
     * memberships entirely — and returning an invented one here would make
     * `roleIn()` lie about what is recorded. StaffTenantPolicy checks the role
     * first for exactly that reason.
     */
    public function roleIn(Tenant $tenant): ?StaffRole
    {
        $membership = $this->relationLoaded('memberships')
            ? $this->memberships
                ->first(fn (StaffMembership $m): bool => $m->tenant_id === $tenant->id && $m->isActive())
            : $this->activeMemberships()->where('tenant_id', $tenant->id)->first();

        return $membership?->role;
    }

    /**
     * @param  Builder<StaffUser>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('staff_users.is_active', true);
    }

    protected static function newFactory(): StaffUserFactory
    {
        return StaffUserFactory::new();
    }
}

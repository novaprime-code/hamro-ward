<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Modules\Accounts\Enums\UserStatus;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A citizen account (docs/12 §11.1, §12.1, D-011).
 *
 * Central, because one person's civic life spans municipalities: they report a
 * pothole where they live and a broken streetlight where they work, and those
 * are two tenant databases. An account per tenant would make them two people.
 *
 * Deliberately NOT a `Person`. A Person is someone who holds or stood for
 * office — a public role, published, sourced. A User is a member of the public
 * who signed up. Keeping them as separate tables with no link between them is
 * what stops the platform from ever quietly asserting that a given account
 * belongs to a given councillor.
 *
 * The identity never reaches a public page. A published issue says "Community
 * report" and nothing else; a moderator sees the display name; the email is
 * reachable only through an audited reveal (docs/12 §12.3).
 *
 * @property string $id
 * @property string $display_name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $preferred_locale
 * @property UserStatus $status
 */
final class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use UsesCentralConnection;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'display_name',
        'email',
        'password',
        'preferred_locale',
    ];

    /**
     * Status and verification are set by the flows that own them — the
     * verification flow, the moderation action, the deletion action — never by
     * mass assignment from a request body.
     *
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
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return HasMany<UserWard, $this>
     */
    public function wards(): HasMany
    {
        return $this->hasMany(UserWard::class, 'user_id');
    }

    /**
     * The one ward the account treats as home — where the report flow starts,
     * and what "your ward" means in the header. Null is a normal state: an
     * account is usable before anyone has saved anything.
     */
    public function primaryWard(): ?UserWard
    {
        return $this->wards()->where('is_primary', true)->first();
    }

    /**
     * Reporting needs a verified address as well as an active account
     * (docs/12 §12.3). Verification is what makes a report traceable to
     * somebody who can be contacted about it, which is the difference between
     * a civic report and an anonymous accusation.
     */
    public function canReport(): bool
    {
        return $this->status->canSignIn() && $this->hasVerifiedEmail();
    }

    /** @param  Builder<User>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active->value);
    }
}

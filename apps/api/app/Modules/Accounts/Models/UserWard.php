<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Modules\Accounts\Enums\WardRelationship;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A ward a citizen has saved, and why (docs/12 §12.1).
 *
 * The entire extent of what this platform records about where someone lives.
 * Not an address — a ward. Enough to route a report and to put the right wards
 * at the top of a list, and nothing finer, because the finer version is an
 * address registry of people who complained about their local government
 * (see the users migration).
 *
 * `created_at` is load-bearing. Under the `saved_wards_only` reporting scope it
 * is what the cooldown is measured from, which is the rule that stops someone
 * saving a ward purely in order to post into it (docs/12 §12.4).
 *
 * @property string $id
 * @property string $user_id
 * @property string $ward_id
 * @property WardRelationship $relationship
 * @property bool $is_primary
 * @property Carbon $created_at
 */
final class UserWard extends Model
{
    /** @use HasFactory<\Database\Factories\UserWardFactory> */
    use HasFactory;

    use HasUuids;
    use UsesCentralConnection;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'ward_id',
        'relationship',
        'is_primary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relationship' => WardRelationship::class,
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<AdminUnit, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(AdminUnit::class, 'ward_id');
    }

    /**
     * Whether this ward has been saved long enough to report into it under the
     * `saved_wards_only` scope (docs/12 §12.4 rule 1).
     *
     * A cooldown of zero means the scope is on but the delay is not, which is a
     * legitimate configuration: it still requires the ward to be saved, just not
     * in advance.
     */
    public function isPastCooldown(int $cooldownHours, ?Carbon $now = null): bool
    {
        if ($cooldownHours <= 0) {
            return true;
        }

        return $this->created_at->addHours($cooldownHours)->lessThanOrEqualTo($now ?? Carbon::now());
    }

    /**
     * When this ward becomes usable for reporting — the exact thing the refusal
     * message has to be able to state, rather than "try again later"
     * (docs/12 §12.4 rule 2).
     */
    public function usableFrom(int $cooldownHours): Carbon
    {
        return $this->created_at->copy()->addHours(max(0, $cooldownHours));
    }
}

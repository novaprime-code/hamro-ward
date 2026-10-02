<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff accounts for tests.
 *
 * Two-factor is **unconfirmed** by default, because that is the state a new
 * staff account is really in: created by an operator admin, enrolment forced
 * at first login (docs/12 §11.5). A test about what a working moderator can do
 * says `->withTwoFactor()`, and the default keeps the unenrolled case — the one
 * the middleware has to refuse — in front of anyone writing a test.
 *
 * @extends Factory<StaffUser>
 */
final class StaffUserFactory extends Factory
{
    protected $model = StaffUser::class;

    /** Hashed once per run; bcrypt is deliberately slow and this is per row. */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password'),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'is_active' => true,
            'locked_until' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Enrolled and confirmed — the state a staff member reaches after their
     * first login.
     */
    public function withTwoFactor(): self
    {
        return $this->state([
            'two_factor_secret' => Str::random(32),
            'two_factor_recovery_codes' => json_encode(
                array_map(static fn (): string => Str::random(10).'-'.Str::random(10), range(1, 8)),
                JSON_THROW_ON_ERROR,
            ),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** Has a secret but never confirmed it: closed the tab at the QR code. */
    public function twoFactorPending(): self
    {
        return $this->state([
            'two_factor_secret' => Str::random(32),
            'two_factor_confirmed_at' => null,
        ]);
    }

    /** Left the project. Deactivated rather than deleted, so the audit trail keeps its actor. */
    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }

    public function locked(): self
    {
        return $this->state(['locked_until' => now()->addMinutes(15)]);
    }
}

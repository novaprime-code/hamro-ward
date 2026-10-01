<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounts\Enums\UserStatus;
use App\Modules\Accounts\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Citizen accounts for tests.
 *
 * Verified by default, because almost every test about an account is a test
 * about what a usable account can do, and the unverified case is the exception
 * worth spelling out at the call site.
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** Hashed once per run; bcrypt is deliberately slow and this is per row. */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'preferred_locale' => 'ne',
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /** Signed up, has not clicked the link — so cannot report (docs/12 §12.3). */
    public function unverified(): self
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function locked(): self
    {
        return $this->state(['status' => UserStatus::Locked]);
    }

    public function pendingDeletion(): self
    {
        return $this->state(['status' => UserStatus::DeletedPending]);
    }
}

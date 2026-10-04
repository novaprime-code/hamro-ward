<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Staff\Enums\GlobalRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Staff accounts for tests. No global role and no memberships by default:
 * authority is always granted at the call site, where the test can see it.
 *
 * @extends Factory<StaffUser>
 */
final class StaffUserFactory extends Factory
{
    protected $model = StaffUser::class;

    protected static ?string $password = null;

    /**
     * @return array<model-property<StaffUser>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password'),
        ];
    }

    public function operatorAdmin(): self
    {
        return $this->afterCreating(function (StaffUser $staff): void {
            $staff->forceFill(['global_role' => GlobalRole::OperatorAdmin])->save();
        });
    }

    public function inactive(): self
    {
        return $this->afterCreating(function (StaffUser $staff): void {
            $staff->forceFill(['is_active' => false])->save();
        });
    }
}

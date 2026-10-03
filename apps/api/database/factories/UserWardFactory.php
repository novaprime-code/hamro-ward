<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounts\Enums\WardRelationship;
use App\Modules\Accounts\Models\User;
use App\Modules\Accounts\Models\UserWard;
use App\Modules\Geography\Models\AdminUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A saved ward. Builds its own account and its own ward chain unless given one.
 *
 * @extends Factory<UserWard>
 */
final class UserWardFactory extends Factory
{
    protected $model = UserWard::class;

    /**
     * @return array<model-property<UserWard>, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (): string => User::factory()->create()->id,
            'ward_id' => fn (): string => AdminUnit::factory()->ward(1)->create()->id,
            'relationship' => WardRelationship::PermanentAddress,
            'is_primary' => false,
        ];
    }

    public function forUser(User $user): self
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function inWard(AdminUnit $ward): self
    {
        return $this->state(['ward_id' => $ward->id]);
    }

    public function relationship(WardRelationship $relationship): self
    {
        return $this->state(['relationship' => $relationship->value]);
    }

    public function primary(): self
    {
        return $this->state(['is_primary' => true]);
    }
}

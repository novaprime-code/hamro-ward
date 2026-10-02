<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffMembership;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A staff member's access to one municipality, for tests.
 *
 * `moderator` by default, because that is the membership most tests are about
 * and the one whose boundaries matter most.
 *
 * @extends Factory<StaffMembership>
 */
final class StaffMembershipFactory extends Factory
{
    protected $model = StaffMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_user_id' => fn (): string => StaffUser::factory()->withTwoFactor()->create()->id,
            'tenant_id' => fn (): string => Tenant::factory()->create()->id,
            'role' => StaffRole::Moderator,
            'granted_by' => null,
            'granted_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function for_(StaffUser $staff, Tenant $tenant): self
    {
        return $this->state([
            'staff_user_id' => $staff->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function role(StaffRole $role): self
    {
        return $this->state(['role' => $role]);
    }

    /**
     * Access that has ended. The row survives, because who could moderate
     * which municipality and when is part of the audit record.
     */
    public function revoked(): self
    {
        return $this->state([
            'granted_at' => now()->subMonths(3),
            'revoked_at' => now()->subDay(),
        ]);
    }
}

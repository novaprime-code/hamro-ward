<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates tenant rows only — no database. Use CreateTenantDatabase for that.
 *
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_unit_id' => (string) Str::uuid(),
            'status' => TenantStatus::Active,
        ];
    }

    public function provisioning(): self
    {
        return $this->state(['status' => TenantStatus::Provisioning]);
    }

    public function maintenance(): self
    {
        return $this->state(['status' => TenantStatus::Maintenance]);
    }
}

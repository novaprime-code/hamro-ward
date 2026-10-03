<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates tenant rows only — no database. Use CreateTenantDatabase for that.
 * Each tenant gets its own fictional local level (tenants.admin_unit_id must
 * reference a local level; HW-E03-F01).
 *
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<model-property<Tenant>, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_unit_id' => fn (): string => AdminUnit::factory()->localLevel()->create()->id,
            'status' => TenantStatus::Active,
        ];
    }

    public function forLocalLevel(AdminUnit $localLevel): self
    {
        return $this->state(['admin_unit_id' => $localLevel->id]);
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

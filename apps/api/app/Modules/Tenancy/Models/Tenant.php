<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use App\Modules\Tenancy\Support\TenantDatabaseName;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One local level = one tenant = one PostgreSQL database (D-010, docs/12 §2).
 *
 * @property string $id
 * @property string $admin_unit_id
 * @property string $tenant_key
 * @property string $database_name
 * @property TenantStatus $status
 * @property string|null $schema_version
 * @property int $reference_version
 * @property Carbon|null $onboarded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'tenants';

    /**
     * tenant_key and database_name are generated on create and never change.
     *
     * @var list<string>
     */
    protected $fillable = [
        'admin_unit_id',
        'status',
        'onboarded_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'provisioning',
        'reference_version' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'reference_version' => 'integer',
            'onboarded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (Tenant $tenant): void {
            if (blank($tenant->tenant_key)) {
                $tenant->tenant_key = TenantDatabaseName::generateUniqueKey();
            }

            $tenant->database_name = TenantDatabaseName::forKey($tenant->tenant_key);
        });

        self::updating(function (Tenant $tenant): void {
            if ($tenant->isDirty(['tenant_key', 'database_name'])) {
                throw TenancyException::immutableIdentity($tenant);
            }
        });
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}

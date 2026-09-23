<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * Technical readiness of a tenant database (docs/12 §2).
 * Editorial visibility is separate: admin_units.is_published.
 */
enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * Statuses whose databases exist and must receive migrations.
     *
     * @return list<self>
     */
    public static function migratable(): array
    {
        return [self::Provisioning, self::Active, self::Maintenance, self::Suspended];
    }
}

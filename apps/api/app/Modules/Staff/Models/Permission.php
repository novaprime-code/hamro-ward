<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * spatie's Permission, with uuid keys and the central connection — same two
 * reasons as Role.
 *
 * Note what these rows are FOR. The per-tenant capabilities are decided by
 * StaffRole::permissions(), in code, not by this table: a membership's
 * permissions have to be the same in every municipality, and a database row
 * someone can edit is not a guarantee of that.
 *
 * These rows exist so the operator_admin role can be granted the whole set
 * through spatie, and so `permissions` has a foreign key to point at. The
 * authority on who may do what per tenant is StaffTenantPolicy.
 */
final class Permission extends SpatiePermission
{
    use HasUuids;
    use UsesCentralConnection;
}

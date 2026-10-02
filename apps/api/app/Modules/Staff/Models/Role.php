<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * spatie's Role, with this project's two house rules applied.
 *
 * **UUID keys.** Every identifier in this schema is a uuid; spatie's model
 * expects an auto-incrementing integer and generates nothing. Left alone it
 * would try to insert a role with no id.
 *
 * **The central connection.** Roles live in the central database with the
 * staff accounts that hold them (docs/12 §3). Without this the model would use
 * whatever connection is default at the time, which inside a tenant request is
 * the tenant's — and the role lookup would quietly find nothing.
 *
 * There is only ever one row here: `operator_admin`. The four membership roles
 * are not roles (StaffRole explains why).
 */
final class Role extends SpatieRole
{
    use HasUuids;
    use UsesCentralConnection;
}

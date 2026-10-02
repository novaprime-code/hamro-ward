<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Staff\Enums\StaffPermission;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\Permission;
use App\Modules\Staff\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission rows, and the one global role (docs/12 §11.5, docs/06 §12).
 *
 * Idempotent, and it has to be: it runs on every deploy, because a permission
 * added in code has to exist as a row before any policy can reference it.
 *
 * **It creates exactly one role.** `operator_admin`, granted every permission.
 * The four membership roles are deliberately absent from the `roles` table —
 * they are memberships of a municipality, and a global `moderator` role is the
 * exact mistake the membership design exists to prevent. If one ever appears
 * in that table, something has started granting national moderation rights by
 * accident — which is why StaffTenantPolicyTest asserts that this table holds
 * exactly one name.
 */
final class StaffRoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [];

        foreach (StaffPermission::cases() as $permission) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $permission->value,
                'guard_name' => StaffRole::GUARD,
            ]);
        }

        $operatorAdmin = Role::query()->firstOrCreate([
            'name' => StaffRole::OPERATOR_ADMIN,
            'guard_name' => StaffRole::GUARD,
        ]);

        /*
         * syncPermissions rather than givePermissionTo: this runs on every
         * deploy, and a permission REMOVED from the enum should stop being
         * granted rather than linger on the role for ever.
         */
        $operatorAdmin->syncPermissions($permissions);

        // spatie caches the permission map; a seeder that leaves the cache
        // holding the old map makes the new permission invisible until
        // something else happens to clear it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

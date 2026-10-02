<?php

declare(strict_types=1);

namespace App\Modules\Staff\Providers;

use App\Modules\Staff\Enums\StaffPermission;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Staff\Policies\StaffTenantPolicy;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Exposes StaffTenantPolicy as Gate abilities, one per permission
 * (docs/06 §12).
 *
 * So a controller writes what it needs and gets the whole model of staff
 * authorization behind it:
 *
 *     Gate::forUser($staff)->authorize('issues.moderate', $tenant);
 *     $staff->can('sources.verify', $tenant);
 *
 * **The tenant is a required argument, and that is the design.** There is no
 * ability here that can be asked without naming a municipality, because there
 * is no such question: `can('issues.moderate')` with no tenant would have to
 * invent an answer, and the convenient invention — "yes, somewhere" — is
 * exactly the national moderation right that memberships exist to prevent.
 * Omitting it denies.
 *
 * Deliberately NOT a `Gate::before` for operator_admin. A blanket before-hook
 * is the usual way to express "admins can do anything", and it would bypass
 * the two-factor and archived-tenant checks in the policy along with
 * everything else. The policy short-circuits for operator admins itself,
 * after those checks.
 */
final class StaffServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerMorphAlias();

        $policy = $this->app->make(StaffTenantPolicy::class);

        foreach (StaffPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                static function (StaffUser $staff, ?Tenant $tenant = null) use ($policy, $permission): bool {
                    // No tenant named, no answer to give. See above.
                    if (! $tenant instanceof Tenant) {
                        return false;
                    }

                    return $policy->allows($staff, $permission, $tenant);
                },
            );
        }
    }

    /**
     * `staff_user` in the morph map.
     *
     * The project enforces a morph map (ProvenanceServiceProvider), and
     * spatie writes `model_has_roles.model_type` through it — so without an
     * alias here, assigning the operator_admin role throws
     * ClassMorphViolationException.
     *
     * morphMap() merges rather than replacing, which is why this provider is
     * registered after ProvenanceServiceProvider in bootstrap/providers.php:
     * that one calls enforceMorphMap(), which replaces, and an entry added
     * before it would be silently dropped.
     *
     * The alias is short and stable on purpose. It is stored in every
     * model_has_roles row, so letting it follow the class name around would
     * turn a namespace tidy-up into a migration — and in the meantime, into
     * every staff member losing their role.
     */
    private function registerMorphAlias(): void
    {
        Relation::morphMap([
            'staff_user' => StaffUser::class,
        ]);
    }
}

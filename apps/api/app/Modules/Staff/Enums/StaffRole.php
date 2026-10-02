<?php

declare(strict_types=1);

namespace App\Modules\Staff\Enums;

/**
 * What a staff member may do **inside one municipality** (docs/12 §11.5).
 *
 * These are memberships, not roles in the spatie sense, and the distinction is
 * structural rather than stylistic. A spatie role is global: granting
 * `moderator` that way would grant it in all 753 local levels at once. A
 * membership names the tenant it belongs to, so a moderator in Koshara is not
 * a moderator in Sonapur, and nobody has to remember to check.
 *
 * The one global role is `operator_admin` (see OPERATOR_ADMIN below), held
 * through spatie, which implies everything everywhere.
 *
 * The grid is docs/06 §12. It lives here, once, rather than being re-derived
 * in each policy — a permission matrix copied into two places is a permission
 * matrix that will disagree with itself.
 */
enum StaffRole: string
{
    /**
     * Clears the queue: issues, corrections, lifecycle. Cannot verify sources
     * and cannot edit the civic record, so the person deciding whether a
     * report is publishable is not also the person who can rewrite what it is
     * about.
     */
    case Moderator = 'moderator';

    /**
     * Checks sources and marks facts verified. Can give a second approval but
     * cannot moderate — the separation that makes the second approval mean
     * something.
     */
    case Verifier = 'verifier';

    /** Imports and edits the civic record. No moderation, no verification. */
    case DataEditor = 'data_editor';

    /** Reads the queue and nothing else. For training, observers, handover. */
    case Viewer = 'viewer';

    /**
     * The global role, granted through spatie/laravel-permission, which
     * implies every permission in every tenant.
     *
     * A string constant rather than a case in this enum, because it is not one
     * of these: these are memberships of a municipality and that is not. Had
     * it been a fifth case, the first `StaffRole::cases()` loop over a tenant's
     * memberships would have offered it as something to grant per municipality.
     */
    public const OPERATOR_ADMIN = 'operator_admin';

    /**
     * The guard these roles and permissions belong to.
     *
     * Staff only. Citizens are a different guard and must never be able to
     * hold one of these, which spatie enforces by keying `roles.guard_name`.
     */
    public const GUARD = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Moderator => 'Moderator',
            self::Verifier => 'Verifier',
            self::DataEditor => 'Data editor',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * The permissions this membership carries, exactly as docs/06 §12 lists
     * them.
     *
     * @return list<StaffPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Moderator => [
                StaffPermission::QueueView,
                StaffPermission::IssuesModerate,
                StaffPermission::CorrectionsModerate,
                StaffPermission::ModerationSecondApprove,
                StaffPermission::IssuesUpdateLifecycle,
            ],
            self::Verifier => [
                StaffPermission::QueueView,
                StaffPermission::ModerationSecondApprove,
                StaffPermission::SourcesVerify,
            ],
            self::DataEditor => [
                StaffPermission::QueueView,
                StaffPermission::DataImport,
                StaffPermission::DataEdit,
            ],
            self::Viewer => [
                StaffPermission::QueueView,
            ],
        };
    }

    public function grants(StaffPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }

    /**
     * Permissions no membership carries — operator admin only.
     *
     * Derived rather than listed, so adding a permission to a role above
     * cannot leave a stale second list claiming it is still reserved.
     *
     * @return list<StaffPermission>
     */
    public static function operatorAdminOnly(): array
    {
        $held = [];

        foreach (self::cases() as $role) {
            foreach ($role->permissions() as $permission) {
                $held[$permission->value] = true;
            }
        }

        return array_values(array_filter(
            StaffPermission::cases(),
            static fn (StaffPermission $permission): bool => ! isset($held[$permission->value]),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Staff\Enums;

/**
 * Everything a staff member can be allowed to do (docs/06 §12).
 *
 * One enum rather than strings, because these names are compared in policies,
 * middleware and tests, and a mistyped string in a permission check does not
 * fail — it quietly denies, or quietly allows if it was the argument to a
 * negation. `StaffPermission::IssuesModerate` cannot be mistyped.
 *
 * These are PER-TENANT capabilities. Holding `IssuesModerate` is always
 * holding it *in a municipality*; nothing here is global except by way of the
 * `operator_admin` role, which bypasses the table in StaffRole. The one
 * exception is marked below.
 */
enum StaffPermission: string
{
    /** Approve, reject or send back a citizen's report. */
    case IssuesModerate = 'issues.moderate';

    /** Act on a correction request about a published fact. */
    case CorrectionsModerate = 'corrections.moderate';

    /**
     * Provide the second approval a restricted item needs (D-002).
     *
     * Verifiers hold this although they cannot moderate. That is the point of
     * it: the second pair of eyes is more useful when it belongs to someone
     * whose ordinary work is checking sources rather than clearing a queue.
     */
    case ModerationSecondApprove = 'moderation.second_approve';

    /** Mark a source as verified, which is what turns a claim into a fact (v0.4). */
    case SourcesVerify = 'sources.verify';

    /** Import or hand-edit the civic record: people, parties, holdings. */
    case DataImport = 'data.import';

    case DataEdit = 'data.edit';

    /** Move an issue along its public lifecycle: acknowledged, in progress, resolved. */
    case IssuesUpdateLifecycle = 'issues.update_lifecycle';

    /** Create staff accounts and grant or revoke memberships. */
    case StaffManage = 'staff.manage';

    /**
     * Read other people's affiliation declarations (D-002).
     *
     * Operator admin only, and this is the most sensitive read on the
     * platform: a declaration states someone's political affiliation, which
     * docs/12 §15 keeps encrypted and out of every other role's reach.
     */
    case AffiliationsView = 'affiliations.view';

    /** The kill switch and the reporting scope (D-012). */
    case SettingsManage = 'settings.manage';

    /** Read the append-only audit log. */
    case AuditView = 'audit.view';

    /**
     * See the moderation queue at all.
     *
     * Every membership role has this, including `viewer`. Seeing the queue is
     * not the same as acting on it, and a role that cannot even look is a role
     * that cannot be handed to a trustee, a journalist under embargo, or
     * someone being trained.
     */
    case QueueView = 'queue.view';
}

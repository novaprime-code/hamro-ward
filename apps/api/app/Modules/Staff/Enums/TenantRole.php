<?php

declare(strict_types=1);

namespace App\Modules\Staff\Enums;

/**
 * What a staff member may do in ONE municipality (docs/12 §11.5). Not a
 * hierarchy: a verifier is not also a moderator. Every role can read the
 * municipality's staff views.
 */
enum TenantRole: string
{
    case Moderator = 'moderator';
    case Verifier = 'verifier';
    case DataEditor = 'data_editor';
    case Viewer = 'viewer';
}

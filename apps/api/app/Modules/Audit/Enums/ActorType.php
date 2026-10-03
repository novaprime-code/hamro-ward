<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

/**
 * Who caused an audit event (docs/05 §9.1). The importer is its own actor
 * rather than "system": a reviewer reading the trail needs to tell a sheet
 * someone prepared by hand from something the platform did on its own.
 */
enum ActorType: string
{
    case Staff = 'staff';
    case System = 'system';
    case Importer = 'importer';
    case Public = 'public';
}

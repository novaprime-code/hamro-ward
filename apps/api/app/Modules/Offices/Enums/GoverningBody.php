<?php

declare(strict_types=1);

namespace App\Modules\Offices\Enums;

/** The four bodies of a local level (docs/02 §3.1). */
enum GoverningBody: string
{
    case Executive = 'executive';
    case Assembly = 'assembly';
    case WardCommittee = 'ward_committee';
    case JudicialCommittee = 'judicial_committee';
}

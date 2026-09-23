<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Enums;

enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Disputed = 'disputed';
    case Rejected = 'rejected';
}

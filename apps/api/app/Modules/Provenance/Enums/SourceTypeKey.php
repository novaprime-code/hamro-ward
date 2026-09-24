<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Enums;

/**
 * Keys of the seeded source_types rows (docs/05 §4.1). The authority ranks and
 * labels are data in the table, not code — only the keys are referenced here.
 */
enum SourceTypeKey: string
{
    case Ecn = 'ecn';
    case GovernmentOfNepal = 'government_of_nepal';
    case LocalLevel = 'local_level';
    case WardOffice = 'ward_office';
    case CandidateSubmission = 'candidate_submission';
    case PartyOfficial = 'party_official';
    case GovernmentDocument = 'government_document';
    case Media = 'media';
    case Community = 'community';
    case SocialMedia = 'social_media';
}

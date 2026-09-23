<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Enums;

/**
 * The eight trust categories (project instructions §3, FR-SRC-04).
 * They are never silently mixed: the UI always shows the label and icon.
 */
enum ProvenanceType: string
{
    case Official = 'official';
    case CandidateSubmitted = 'candidate_submitted';
    case PublicRecord = 'public_record';
    case VerifiedCommunityReport = 'verified_community_report';
    case CommunityReport = 'community_report';
    case MediaReport = 'media_report';
    case AiGeneratedSummary = 'ai_generated_summary';
    case UnverifiedClaim = 'unverified_claim';
}

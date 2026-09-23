<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Provenance\Enums\ProvenanceType;
use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Models\SourceType;
use Illuminate\Database\Seeder;

/**
 * The source hierarchy (project instructions §4, docs/05 §4.1).
 * Rank 1 is the highest authority. Nepali labels need native review.
 */
final class SourceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [SourceTypeKey::Ecn, 1, 'निर्वाचन आयोग', 'Election Commission Nepal', ProvenanceType::Official],
            [SourceTypeKey::GovernmentOfNepal, 2, 'नेपाल सरकार', 'Government of Nepal', ProvenanceType::Official],
            [SourceTypeKey::LocalLevel, 3, 'स्थानीय तह', 'Local level', ProvenanceType::Official],
            [SourceTypeKey::WardOffice, 4, 'वडा कार्यालय', 'Ward office', ProvenanceType::Official],
            [SourceTypeKey::CandidateSubmission, 5, 'उम्मेदवारले पेश गरेको', 'Candidate submission', ProvenanceType::CandidateSubmitted],
            [SourceTypeKey::PartyOfficial, 6, 'दलको आधिकारिक स्रोत', 'Party official source', ProvenanceType::CandidateSubmitted],
            [SourceTypeKey::GovernmentDocument, 7, 'सरकारी कागजात', 'Government document', ProvenanceType::PublicRecord],
            [SourceTypeKey::Media, 8, 'समाचार माध्यम', 'News media', ProvenanceType::MediaReport],
            [SourceTypeKey::Community, 9, 'नागरिक स्रोत', 'Community source', ProvenanceType::CommunityReport],
            [SourceTypeKey::SocialMedia, 10, 'सामाजिक सञ्जाल', 'Social media', ProvenanceType::UnverifiedClaim],
        ];

        foreach ($types as [$key, $rank, $labelNe, $labelEn, $provenance]) {
            SourceType::query()->updateOrCreate(
                ['key' => $key->value],
                [
                    'authority_rank' => $rank,
                    'label_ne' => $labelNe,
                    'label_en' => $labelEn,
                    'default_provenance_type' => $provenance,
                ],
            );
        }
    }
}

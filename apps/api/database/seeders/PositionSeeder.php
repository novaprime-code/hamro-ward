<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Offices\Enums\AppointmentType;
use App\Modules\Offices\Enums\ElectionMethod;
use App\Modules\Offices\Enums\GoverningBody;
use App\Modules\Offices\Enums\PositionKey;
use App\Modules\Offices\Enums\SeatCategory;
use App\Modules\Offices\Models\Position;
use Illuminate\Database\Seeder;

/**
 * The local-level positions catalogue (docs/02 §3.1–3.2, §4.1, docs/05 §5.1).
 *
 * A voter in any ward fills seven seats: mayor and deputy mayor for the whole
 * local level, then ward chair, one woman ward member, one Dalit woman ward
 * member and two open ward members for their own ward. Rural municipalities use
 * chairperson and vice-chairperson for the first two, which is why those are
 * separate keys with their own applicable types rather than one key with a
 * label that switches.
 *
 * Executive members elected by the assembly are included because they really do
 * govern, and "who governs my municipality" would be wrong without them — but
 * they are indirect, so v_current_seats does not generate them as seats and the
 * candidate directory ignores them (docs/02 §4.5).
 *
 * Seat counts come from the Constitution's composition rules as summarised in
 * docs/02 §3.1: urban executives take 5 women and 3 Dalit/minority members,
 * rural ones 4 and 2.
 *
 * Nepali titles are drafts pending native review (blocker B7).
 */
final class PositionSeeder extends Seeder
{
    private const URBAN = ['metropolitan_city', 'sub_metropolitan_city', 'municipality'];

    private const RURAL = ['rural_municipality'];

    private const ALL = [...self::URBAN, ...self::RURAL];

    public function run(): void
    {
        foreach ($this->positions() as $position) {
            Position::query()->updateOrCreate(
                ['key' => $position['key']],
                $position,
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function positions(): array
    {
        return [
            $this->direct(
                PositionKey::Mayor, 'नगर प्रमुख', 'Mayor',
                GoverningBody::Executive, 'local_level', SeatCategory::Open,
                self::URBAN, 1, ballotOrder: 1,
            ),
            $this->direct(
                PositionKey::Chairperson, 'गाउँपालिका अध्यक्ष', 'Chairperson',
                GoverningBody::Executive, 'local_level', SeatCategory::Open,
                self::RURAL, 1, ballotOrder: 1,
            ),
            $this->direct(
                PositionKey::DeputyMayor, 'नगर उपप्रमुख', 'Deputy Mayor',
                GoverningBody::Executive, 'local_level', SeatCategory::Open,
                self::URBAN, 1, ballotOrder: 2,
            ),
            $this->direct(
                PositionKey::ViceChairperson, 'गाउँपालिका उपाध्यक्ष', 'Vice-Chairperson',
                GoverningBody::Executive, 'local_level', SeatCategory::Open,
                self::RURAL, 1, ballotOrder: 2,
            ),
            $this->direct(
                PositionKey::WardChair, 'वडाध्यक्ष', 'Ward Chairperson',
                GoverningBody::WardCommittee, 'ward', SeatCategory::Open,
                self::ALL, 1, ballotOrder: 3,
            ),
            $this->direct(
                PositionKey::WardMemberWoman, 'महिला वडा सदस्य', 'Ward Member (woman)',
                GoverningBody::WardCommittee, 'ward', SeatCategory::Woman,
                self::ALL, 1, ballotOrder: 4,
            ),
            $this->direct(
                PositionKey::WardMemberDalitWoman, 'दलित महिला वडा सदस्य', 'Ward Member (Dalit woman)',
                GoverningBody::WardCommittee, 'ward', SeatCategory::DalitWoman,
                self::ALL, 1, ballotOrder: 5,
            ),
            $this->direct(
                PositionKey::WardMemberOpen, 'वडा सदस्य', 'Ward Member',
                GoverningBody::WardCommittee, 'ward', SeatCategory::Open,
                self::ALL, 2, ballotOrder: 6,
            ),
            $this->indirect(
                PositionKey::ExecutiveMemberWoman, 'कार्यपालिका सदस्य (महिला)', 'Executive Member (woman)',
                SeatCategory::Woman,
                ['metropolitan_city' => 5, 'sub_metropolitan_city' => 5, 'municipality' => 5, 'rural_municipality' => 4],
            ),
            $this->indirect(
                PositionKey::ExecutiveMemberDalitMinority, 'कार्यपालिका सदस्य (दलित वा अल्पसंख्यक)',
                'Executive Member (Dalit or minority)',
                SeatCategory::DalitMinority,
                ['metropolitan_city' => 3, 'sub_metropolitan_city' => 3, 'municipality' => 3, 'rural_municipality' => 2],
            ),
        ];
    }

    /**
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    private function direct(
        PositionKey $key,
        string $titleNe,
        string $titleEn,
        GoverningBody $body,
        string $constituencyLevel,
        SeatCategory $seatCategory,
        array $types,
        int $seats,
        int $ballotOrder,
    ): array {
        return [
            'key' => $key->value,
            'title_ne' => $titleNe,
            'title_en' => $titleEn,
            'body' => $body,
            'constituency_level' => $constituencyLevel,
            'seat_category' => $seatCategory,
            'election_method' => ElectionMethod::Direct,
            'appointment_type' => AppointmentType::ElectedDirect,
            'applies_to_local_level_types' => $types,
            'seats_per_constituency' => array_fill_keys($types, $seats),
            'ballot_order' => $ballotOrder,
        ];
    }

    /**
     * @param  array<string, int>  $seats
     * @return array<string, mixed>
     */
    private function indirect(
        PositionKey $key,
        string $titleNe,
        string $titleEn,
        SeatCategory $seatCategory,
        array $seats,
    ): array {
        return [
            'key' => $key->value,
            'title_ne' => $titleNe,
            'title_en' => $titleEn,
            'body' => GoverningBody::Executive,
            'constituency_level' => 'local_level',
            'seat_category' => $seatCategory,
            'election_method' => ElectionMethod::Indirect,
            'appointment_type' => AppointmentType::ElectedIndirect,
            'applies_to_local_level_types' => array_keys($seats),
            'seats_per_constituency' => $seats,
            'ballot_order' => null,
        ];
    }
}

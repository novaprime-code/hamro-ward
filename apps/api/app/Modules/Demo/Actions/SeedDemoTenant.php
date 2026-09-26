<?php

declare(strict_types=1);

namespace App\Modules\Demo\Actions;

use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Demo\Data\DemoLocalLevel;
use App\Modules\Demo\Exceptions\DemoDataException;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Offices\Enums\PositionKey;
use App\Modules\Offices\Enums\VacancyReason;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\TenantPosition;
use App\Modules\Offices\Models\Vacancy;
use App\Modules\Offices\Models\WardOffice;
use App\Modules\Provenance\Enums\ProvenanceType;
use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Models\TenantSource;
use App\Modules\Provenance\Models\TenantSourceLink;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Fills one demonstration tenant with seats, evidence and ward offices
 * (docs/13 §5).
 *
 * The point is NOT a tidy ward where every seat is filled and every fact is
 * confirmed. That demonstrates a directory, and anyone can build a directory.
 * What distinguishes Hamro Ward is what it does when the information is
 * incomplete, unsourced or contradictory — so the seed produces all four
 * situations on purpose, by ward number:
 *
 *   ward % 4 == 1  every seat held, every holding verified
 *   ward % 4 == 2  held and verified, except the reserved Dalit woman seat,
 *                  which is verifiably vacant because nobody stood — the real
 *                  2022 pattern in 123 local levels (docs/02 §4.2)
 *   ward % 4 == 3  holders recorded but unsourced, so every seat reads
 *                  not_verified: we know something, we cannot yet stand behind it
 *   ward % 4 == 0  nothing entered at all, which must look different from
 *                  "nobody holds this office"
 *
 * Ward 1 additionally carries a source conflict: two sources disagreeing about
 * the holder's party, both preserved, publication of that one field blocked.
 *
 * Sources are attributed to the invented municipality and its ward offices,
 * never to the Election Commission or the Government of Nepal (docs/13 §4).
 * Invented data must not borrow a real national institution's authority.
 *
 * ---------------------------------------------------------------------------
 * Every write goes through put(), which uses forceFill rather than
 * updateOrCreate. That is not a preference — see the note on put() itself.
 * ---------------------------------------------------------------------------
 */
final class SeedDemoTenant
{
    public function __construct(private readonly TenantManager $tenancy) {}

    /** @return array{holdings: int, vacancies: int, sources: int, ward_offices: int} */
    public function handle(Tenant $tenant, DemoLocalLevel $demo): array
    {
        return $this->tenancy->run($tenant, function () use ($demo): array {
            if (TenantPosition::query()->count() === 0) {
                throw DemoDataException::noPositions();
            }

            $counts = ['holdings' => 0, 'vacancies' => 0, 'sources' => 0, 'ward_offices' => 0];

            DB::connection((string) config('tenancy.tenant_connection'))
                ->transaction(function () use ($demo, &$counts): void {
                    $localLevel = TenantAdminUnit::localLevel();

                    if ($localLevel === null) {
                        throw DemoDataException::geographyMissing($demo->slugPath());
                    }

                    $counts['sources'] += $this->sources($demo);
                    $counts['holdings'] += $this->leadershipSeats($demo, $localLevel);

                    $wards = TenantAdminUnit::query()
                        ->wards()
                        ->where('ward_number', '<=', $demo->seededWards())
                        ->get();

                    foreach ($wards as $ward) {
                        $result = $this->wardSeats($demo, $ward);
                        $counts['holdings'] += $result['holdings'];
                        $counts['vacancies'] += $result['vacancies'];
                        $counts['ward_offices'] += $this->wardOffice($demo, $ward);
                    }
                });

            return $counts;
        }, allowInactive: true);
    }

    /**
     * Insert or update one row by its deterministic id, bypassing
     * mass-assignment protection.
     *
     * updateOrCreate() runs the attributes through fill(), which silently
     * DROPS anything missing from the model's $fillable — including `id`. A
     * model with HasUuids then generates a fresh uuid, so the row exists under
     * an identifier the seeder has never seen. Nothing errors. The next insert
     * that references the intended id fails instead, in a different table,
     * pointing at a source that "does not exist in this tenant".
     *
     * The Provenance models have exactly that shape. Rather than depend on the
     * $fillable list of every model this action touches — most of which belong
     * to other modules and may change — the seeder writes with forceFill and
     * keeps its own ids. A seeder is not user input; there is nothing here to
     * protect against.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function put(string $model, string $id, array $attributes): Model
    {
        /** @var TModel $row */
        $row = $model::query()->find($id) ?? new $model;

        $row->forceFill(['id' => $id, ...$attributes])->save();

        return $row;
    }

    /**
     * A value for a jsonb column, correct whether or not the model casts it.
     *
     * With a json/array cast Eloquent encodes on write, so the raw PHP value is
     * what it wants. Without one, PostgreSQL needs valid JSON text — a bare
     * string is rejected by jsonb.
     */
    private function jsonValue(mixed $value): mixed
    {
        return (new TenantSourceLink)->hasCast('asserted_value', ['array', 'json', 'object', 'collection'])
            ? $value
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Three sources per tenant: the municipality's own record, a ward-office
     * notice, and one social-media claim that exists to be contradicted. All
     * use the .example reserved domain, which can never be registered by
     * anyone, so a demonstration link cannot one day resolve to a stranger's
     * website.
     */
    private function sources(DemoLocalLevel $demo): int
    {
        $sources = [
            ['record', SourceTypeKey::LocalLevel, 'स्थानीय निर्वाचन परिणाम अभिलेख (नमुना)', 'election-record'],
            ['notice', SourceTypeKey::WardOffice, 'वडा कार्यालय सूचना (नमुना)', 'ward-notice'],
            ['claim', SourceTypeKey::SocialMedia, 'सामाजिक सञ्जालमा गरिएको दाबी (नमुना)', 'social-claim'],
        ];

        foreach ($sources as [$key, $type, $title, $path]) {
            $this->put(TenantSource::class, DemoDataset::id('source', $demo->key, $key), [
                'source_type_key' => $type->value,
                'title' => $title,
                'publisher' => $demo->nameNe,
                'url' => "https://demo.hamroward.example/{$demo->key}/{$path}.pdf",
                'language' => 'ne',
                'published_at' => '2022-06-15',
                'retrieved_at' => now(),
            ]);
        }

        return count($sources);
    }

    /** Mayor and deputy, or chair and vice-chair — held and verified. */
    private function leadershipSeats(DemoLocalLevel $demo, TenantAdminUnit $localLevel): int
    {
        $keys = $demo->type->value === 'rural_municipality'
            ? [PositionKey::Chairperson, PositionKey::ViceChairperson]
            : [PositionKey::Mayor, PositionKey::DeputyMayor];

        $seated = 0;

        foreach ($keys as $ordinal => $positionKey) {
            $holding = $this->holding(
                $demo,
                $positionKey,
                (string) $localLevel->id,
                seatIndex: 1,
                personIndex: $ordinal,
                seatOrdinal: $ordinal,
            );

            $this->verify($demo, (string) $holding->id, 'office_holding');
            $seated++;
        }

        return $seated;
    }

    /** @return array{holdings: int, vacancies: int} */
    private function wardSeats(DemoLocalLevel $demo, TenantAdminUnit $ward): array
    {
        $pattern = $ward->ward_number % 4;

        if ($pattern === 0) {
            // Nothing is known about this ward. Every seat reads not_verified,
            // which is the honest answer and must look different from "nobody
            // holds this office".
            return ['holdings' => 0, 'vacancies' => 0];
        }

        $seats = [
            [PositionKey::WardChair, 1],
            [PositionKey::WardMemberWoman, 1],
            [PositionKey::WardMemberDalitWoman, 1],
            [PositionKey::WardMemberOpen, 1],
            [PositionKey::WardMemberOpen, 2],
        ];

        $holdings = 0;
        $vacancies = 0;

        foreach ($seats as $offset => [$positionKey, $seatIndex]) {
            $personIndex = 4 + ($ward->ward_number - 1) * 5 + $offset;
            $seatOrdinal = ($ward->ward_number * 7) + $offset;

            if ($pattern === 2 && $positionKey === PositionKey::WardMemberDalitWoman) {
                $vacancy = $this->put(
                    Vacancy::class,
                    DemoDataset::id('vacancy', $demo->key, (string) $ward->ward_number),
                    [
                        'position_key' => $positionKey->value,
                        'constituency_id' => (string) $ward->id,
                        'seat_index' => $seatIndex,
                        'vacant_from' => '2022-05-30',
                        'reason' => VacancyReason::NoCandidate->value,
                        'note' => 'यो आरक्षित पदमा कुनै उम्मेदवार उठेनन् (नमुना तथ्यांक)।',
                    ],
                );

                $this->verify($demo, (string) $vacancy->id, 'vacancy');
                $vacancies++;

                continue;
            }

            $holding = $this->holding($demo, $positionKey, (string) $ward->id, $seatIndex, $personIndex, $seatOrdinal);
            $holdings++;

            // Pattern 3 leaves holdings deliberately unsourced.
            if ($pattern !== 3) {
                $this->verify($demo, (string) $holding->id, 'office_holding');
            }

            if ($ward->ward_number === 1 && $positionKey === PositionKey::WardChair) {
                $this->partyConflict($demo, $holding);
            }
        }

        return ['holdings' => $holdings, 'vacancies' => $vacancies];
    }

    private function holding(
        DemoLocalLevel $demo,
        PositionKey $positionKey,
        string $constituencyId,
        int $seatIndex,
        int $personIndex,
        int $seatOrdinal,
    ): OfficeHolding {
        $partyId = SeedDemoDirectory::partyIdFor($seatOrdinal);

        /** @var OfficeHolding $holding */
        $holding = $this->put(
            OfficeHolding::class,
            DemoDataset::id('holding', $demo->key, $constituencyId, $positionKey->value, (string) $seatIndex),
            [
                'person_id' => SeedDemoDirectory::personId($demo, $personIndex),
                'position_key' => $positionKey->value,
                'constituency_id' => $constituencyId,
                'seat_index' => $seatIndex,
                'party_id' => $partyId,
                'is_independent' => $partyId === null,
                'start_date' => '2022-05-30',
                'term_label' => '2022–2027',
            ],
        );

        return $holding;
    }

    /** A verified link from the municipality's own election record. */
    private function verify(DemoLocalLevel $demo, string $subjectId, string $subjectType): void
    {
        $this->put(
            TenantSourceLink::class,
            DemoDataset::id('link', $demo->key, $subjectType, $subjectId),
            [
                'source_id' => DemoDataset::id('source', $demo->key, 'record'),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'provenance_type' => ProvenanceType::Official->value,
                'verification_status' => 'verified',
                'verified_by' => DemoDataset::id('staff', 'demo-verifier'),
                'verified_at' => now(),
                'second_approved_by' => DemoDataset::id('staff', 'demo-approver'),
            ],
        );
    }

    /**
     * Ward 1's chair has two sources disagreeing about which party they were
     * elected for: the municipality's record, and an unverified social-media
     * claim. Both are kept. The platform shows the disagreement rather than
     * choosing quietly, which is the behaviour the whole trust model rests on
     * (project instructions §4).
     */
    private function partyConflict(DemoLocalLevel $demo, OfficeHolding $holding): void
    {
        $parties = DemoDataset::parties();

        $this->put(
            TenantSourceLink::class,
            DemoDataset::id('link', $demo->key, 'conflict-official', (string) $holding->id),
            [
                'source_id' => DemoDataset::id('source', $demo->key, 'record'),
                'subject_type' => 'office_holding',
                'subject_id' => (string) $holding->id,
                'field_path' => 'party_id',
                'provenance_type' => ProvenanceType::Official->value,
                'asserted_value' => $this->jsonValue($parties[1]['en']),
                'verification_status' => 'verified',
                'verified_by' => DemoDataset::id('staff', 'demo-verifier'),
                'verified_at' => now(),
            ],
        );

        $this->put(
            TenantSourceLink::class,
            DemoDataset::id('link', $demo->key, 'conflict-claim', (string) $holding->id),
            [
                'source_id' => DemoDataset::id('source', $demo->key, 'claim'),
                'subject_type' => 'office_holding',
                'subject_id' => (string) $holding->id,
                'field_path' => 'party_id',
                'provenance_type' => ProvenanceType::UnverifiedClaim->value,
                'asserted_value' => $this->jsonValue($parties[2]['en']),
                'verification_status' => 'unverified',
            ],
        );
    }

    private function wardOffice(DemoLocalLevel $demo, TenantAdminUnit $ward): int
    {
        if ($ward->level !== AdminLevel::Ward) {
            return 0;
        }

        $this->put(
            WardOffice::class,
            DemoDataset::id('ward_office', $demo->key, (string) $ward->ward_number),
            [
                'ward_id' => (string) $ward->id,
                'address_ne' => $demo->nameNe.' वडा नं. '.$ward->ward_number.' कार्यालय (नमुना ठेगाना)',
                'address_en' => $demo->nameEn.' Ward '.$ward->ward_number.' Office (demo address)',
                'phone' => '025-'.str_pad((string) (580000 + $ward->ward_number), 6, '0', STR_PAD_LEFT),
                'email' => "ward{$ward->ward_number}@{$demo->key}.demo.example",
                'office_hours_ne' => 'आइतबार–शुक्रबार, बिहान १० – बेलुका ५',
                'office_hours_en' => 'Sunday to Friday, 10am to 5pm',
            ],
        );

        return 1;
    }
}
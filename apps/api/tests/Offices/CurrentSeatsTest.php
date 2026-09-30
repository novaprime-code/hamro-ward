<?php

declare(strict_types=1);

use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Offices\Actions\RecordOfficeHolding;
use App\Modules\Offices\Actions\RecordVacancy;
use App\Modules\Offices\DataTransferObjects\SeatRow;
use App\Modules\Offices\Enums\PositionKey;
use App\Modules\Offices\Enums\SeatState;
use App\Modules\Offices\Enums\VacancyReason;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Queries\CurrentSeatsQuery;
use App\Modules\Provenance\Enums\ProvenanceType;
use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Models\TenantSource;
use App\Modules\Provenance\Models\TenantSourceLink;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
});

/** Marks a subject verified, the way a second approver would (D-002). */
function verify(string $subjectType, string $subjectId): void
{
    $source = TenantSource::query()->create([
        'source_type_key' => SourceTypeKey::LocalLevel->value,
        'title' => 'Local level notice',
        'url' => 'https://example.test/notice.pdf',
        'retrieved_at' => now(),
    ]);

    TenantSourceLink::query()->create([
        'source_id' => $source->id,
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'provenance_type' => ProvenanceType::Official,
        'verification_status' => 'verified',
        'verified_by' => (string) Str::uuid(),
        'verified_at' => now(),
    ]);
}

it('generates every seat a ward is supposed to have, filled or not', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            [$wardId] = tenantSeatContext();

            $seats = app(CurrentSeatsQuery::class)->forConstituency($wardId);

            // Ward chair, woman member, Dalit woman member, two open members.
            expect($seats)->toHaveCount(5)
                ->and($seats->pluck('state')->unique()->all())->toBe([SeatState::NotVerified]);
        });
    }, tenantWithPublishedWards());
});

it('shows a ward page both of its constituencies, ward first', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            [$wardId, $localLevelId] = tenantSeatContext();

            $seats = app(CurrentSeatsQuery::class)->forWardPage($wardId, $localLevelId);

            expect($seats)->toHaveCount(7)
                ->and($seats->first()->constituencyLevel)->toBe('ward')
                ->and($seats->last()->positionKey)->toBe(PositionKey::DeputyMayor->value);
        });
    }, tenantWithPublishedWards());
});

it('uses rural titles in a rural municipality', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            [, $localLevelId] = tenantSeatContext();

            $keys = app(CurrentSeatsQuery::class)
                ->forConstituency($localLevelId)
                ->pluck('positionKey')
                ->all();

            expect($keys)->toBe([
                PositionKey::Chairperson->value,
                PositionKey::ViceChairperson->value,
            ]);
        });
    }, tenantWithPublishedWards(1, LocalLevelType::RuralMunicipality));
});

it('leaves assembly-elected executive members out of the seat list', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            [, $localLevelId] = tenantSeatContext();

            $keys = app(CurrentSeatsQuery::class)->forConstituency($localLevelId)->pluck('positionKey');

            expect($keys)->not->toContain(PositionKey::ExecutiveMemberWoman->value);
        });
    }, tenantWithPublishedWards());
});

it('calls a seat not_verified while its holder has no verified source', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $person = Person::factory()->published()->create(['full_name_en' => 'Hemanti Ghimire']);

        app(TenantManager::class)->run($tenant, function () use ($person): void {
            [$wardId] = tenantSeatContext();

            app(RecordOfficeHolding::class)->handle(
                personId: $person->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
            );

            $seat = app(CurrentSeatsQuery::class)
                ->forConstituency($wardId)
                ->firstWhere('positionKey', PositionKey::WardChair->value);

            // The name is known but unconfirmed — the page must say so rather
            // than print it as fact (FR-SRC-02).
            expect($seat->state)->toBe(SeatState::NotVerified)
                ->and($seat->hasUnverifiedHolder())->toBeTrue()
                ->and($seat->person->name('en'))->toBe('Hemanti Ghimire');
        });
    }, tenantWithPublishedWards());
});

it('calls a seat held once a verified source supports the holding', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $person = Person::factory()->published()->create();
        $party = Party::factory()->published()->create(['abbreviation_en' => 'EP']);

        app(TenantManager::class)->run($tenant, function () use ($person, $party): void {
            [$wardId] = tenantSeatContext();

            $holding = app(RecordOfficeHolding::class)->handle(
                personId: $person->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
                partyId: $party->id,
                attributes: ['term_label' => '2022–2027'],
            );

            verify('office_holding', $holding->id);

            $seat = app(CurrentSeatsQuery::class)
                ->forConstituency($wardId)
                ->firstWhere('positionKey', PositionKey::WardChair->value);

            expect($seat->state)->toBe(SeatState::Held)
                ->and($seat->isHeld())->toBeTrue()
                ->and($seat->party->abbreviation('en'))->toBe('EP')
                ->and($seat->termLabel)->toBe('2022–2027');
        });
    }, tenantWithPublishedWards());
});

it('calls a seat vacant only once the vacancy itself is verified', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            [$wardId] = tenantSeatContext();

            $vacancy = app(RecordVacancy::class)->handle(
                positionKey: PositionKey::WardMemberDalitWoman->value,
                constituencyId: $wardId,
                vacantFrom: Carbon::parse('2022-05-30'),
                reason: VacancyReason::NoCandidate,
            );

            $before = app(CurrentSeatsQuery::class)
                ->forConstituency($wardId)
                ->firstWhere('positionKey', PositionKey::WardMemberDalitWoman->value);

            expect($before->state)->toBe(SeatState::NotVerified);

            verify('vacancy', $vacancy->id);

            $after = app(CurrentSeatsQuery::class)
                ->forConstituency($wardId)
                ->firstWhere('positionKey', PositionKey::WardMemberDalitWoman->value);

            expect($after->state)->toBe(SeatState::Vacant)
                ->and($after->vacancyReason)->toBe(VacancyReason::NoCandidate);
        });
    }, tenantWithPublishedWards());
});

it('drops back to the successor once a term ends', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $outgoing = Person::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($outgoing): void {
            [$wardId] = tenantSeatContext();

            $holding = app(RecordOfficeHolding::class)->handle(
                personId: $outgoing->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2020-01-01'),
                attributes: [
                    'end_date' => Carbon::yesterday()->toDateString(),
                    'end_reason' => 'resignation',
                ],
            );

            verify('office_holding', $holding->id);

            $seat = app(CurrentSeatsQuery::class)
                ->forConstituency($wardId)
                ->firstWhere('positionKey', PositionKey::WardChair->value);

            // The seat still exists; nobody currently holds it, and nothing
            // verified says it is vacant either.
            expect($seat->state)->toBe(SeatState::NotVerified)
                ->and($seat->person)->toBeNull();
        });
    }, tenantWithPublishedWards());
});

it('states how much of a ward is confirmed instead of hiding the gaps', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $person = Person::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($person): void {
            [$wardId] = tenantSeatContext();

            $holding = app(RecordOfficeHolding::class)->handle(
                personId: $person->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
            );
            verify('office_holding', $holding->id);

            $vacancy = app(RecordVacancy::class)->handle(
                positionKey: PositionKey::WardMemberDalitWoman->value,
                constituencyId: $wardId,
                vacantFrom: Carbon::parse('2022-05-30'),
                reason: VacancyReason::NoCandidate,
            );
            verify('vacancy', $vacancy->id);

            expect(app(CurrentSeatsQuery::class)->coverageFor($wardId))->toBe([
                'total' => 5,
                'held' => 1,
                'vacant' => 1,
                'not_verified' => 3,
            ]);
        });
    }, tenantWithPublishedWards());
});

it('keeps an unpublished ward out of the seat list entirely', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            [, $localLevelId] = tenantSeatContext();

            $wardNumbers = app(CurrentSeatsQuery::class)
                ->forLocalLevel($localLevelId)
                ->pluck('wardNumber')
                ->filter()
                ->unique()
                ->values()
                ->all();

            expect($wardNumbers)->toBe([1]);
        });
    }, tenantWithOneUnpublishedWard());
});

it('resolves people and parties without a query per seat', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $people = Person::factory()->count(5)->create();
        $party = Party::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($people, $party): void {
            [$wardId] = tenantSeatContext();

            $seats = [
                [PositionKey::WardChair->value, 1],
                [PositionKey::WardMemberWoman->value, 1],
                [PositionKey::WardMemberDalitWoman->value, 1],
                [PositionKey::WardMemberOpen->value, 1],
                [PositionKey::WardMemberOpen->value, 2],
            ];

            foreach ($seats as $index => [$positionKey, $seatIndex]) {
                app(RecordOfficeHolding::class)->handle(
                    personId: $people[$index]->id,
                    positionKey: $positionKey,
                    constituencyId: $wardId,
                    startDate: Carbon::parse('2022-05-30'),
                    seatIndex: $seatIndex,
                    partyId: $party->id,
                );
            }

            $central = 0;
            DB::listen(function (QueryExecuted $query) use (&$central): void {
                if ($query->connectionName === 'central') {
                    $central++;
                }
            });

            $rows = app(CurrentSeatsQuery::class)->forConstituency($wardId);

            // Five seats, five different people, one party — two central
            // queries, not six.
            expect($rows)->toHaveCount(5)
                ->and($rows->every(fn (SeatRow $row): bool => $row->person !== null))->toBeTrue()
                ->and($central)->toBe(2);
        });
    }, tenantWithPublishedWards());
});

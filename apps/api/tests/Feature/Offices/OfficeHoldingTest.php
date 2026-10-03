<?php

declare(strict_types=1);

use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Offices\Actions\EndOfficeHolding;
use App\Modules\Offices\Actions\RecordOfficeHolding;
use App\Modules\Offices\Actions\RecordVacancy;
use App\Modules\Offices\Enums\HoldingEndReason;
use App\Modules\Offices\Enums\PositionKey;
use App\Modules\Offices\Enums\VacancyReason;
use App\Modules\Offices\Exceptions\OfficesException;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Models\Vacancy;
use App\Modules\Offices\Models\WardOffice;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
});

it('seats a person in a ward and reads them back', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $person = Person::factory()->published()->create();

        app(TenantManager::class)->run($tenant, function () use ($person): void {
            [$wardId] = tenantSeatContext();

            $holding = app(RecordOfficeHolding::class)->handle(
                personId: $person->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
                attributes: ['term_label' => '2022–2027'],
            );

            expect($holding->isCurrent())->toBeTrue()
                // Crosses into the central database (D-014).
                ->and($holding->person->id)->toBe($person->id)
                ->and($holding->position->title_en)->toBe('Ward Chairperson');
        });
    }, tenantWithPublishedWards());
});

it('refuses to seat somebody who is not in the central directory', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            [$wardId] = tenantSeatContext();

            expect(fn () => app(RecordOfficeHolding::class)->handle(
                personId: (string) Str::uuid(),
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
            ))->toThrow(OfficesException::class);
        });
    }, tenantWithPublishedWards());
});

it('refuses to seat a person who was merged into another', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $kept = Person::factory()->published()->create();
        $duplicate = Person::factory()->mergedInto($kept)->create();

        app(TenantManager::class)->run($tenant, function () use ($duplicate): void {
            [$wardId] = tenantSeatContext();

            expect(fn () => app(RecordOfficeHolding::class)->handle(
                personId: $duplicate->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
            ))->toThrow(OfficesException::class);
        });
    }, tenantWithPublishedWards());
});

it('refuses a holding that is independent and has a party', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $person = Person::factory()->create();
        $party = Party::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($person, $party): void {
            [$wardId] = tenantSeatContext();

            expect(fn () => app(RecordOfficeHolding::class)->handle(
                personId: $person->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
                partyId: $party->id,
                isIndependent: true,
            ))->toThrow(OfficesException::class);
        });
    }, tenantWithPublishedWards());
});

it('makes two simultaneous holders of one seat impossible in the database itself', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $first = Person::factory()->create();
        $second = Person::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($first, $second): void {
            [$wardId] = tenantSeatContext();

            app(RecordOfficeHolding::class)->handle(
                personId: $first->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
            );

            // Not through the action — straight at the table, to prove the
            // exclusion constraint and not the PHP check.
            expectRejectedByTenantDatabase(fn () => OfficeHolding::query()->create([
                'person_id' => $second->id,
                'position_key' => PositionKey::WardChair->value,
                'constituency_id' => $wardId,
                'seat_index' => 1,
                'start_date' => '2024-01-01',
            ]));
        });
    }, tenantWithPublishedWards());
});

it('allows a handover on the day the previous term ends', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $outgoing = Person::factory()->create();
        $incoming = Person::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($outgoing, $incoming): void {
            [$wardId] = tenantSeatContext();

            $holding = app(RecordOfficeHolding::class)->handle(
                personId: $outgoing->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
            );

            app(EndOfficeHolding::class)->handle(
                $holding,
                Carbon::parse('2024-01-01'),
                HoldingEndReason::Resignation,
            );

            $successor = app(RecordOfficeHolding::class)->handle(
                personId: $incoming->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2024-01-01'),
            );

            expect($successor->isCurrent())->toBeTrue()
                ->and(OfficeHolding::query()->count())->toBe(2);
        });
    }, tenantWithPublishedWards());
});

it('refuses a ward position seated by the whole local level', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $person = Person::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($person): void {
            [, $localLevelId] = tenantSeatContext();

            expectRejectedByTenantDatabase(fn () => OfficeHolding::query()->create([
                'person_id' => $person->id,
                'position_key' => PositionKey::WardChair->value,
                'constituency_id' => $localLevelId,
                'start_date' => '2022-05-30',
            ]));
        });
    }, tenantWithPublishedWards());
});

it('refuses a seat index the catalogue does not provide', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $person = Person::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($person): void {
            [$wardId] = tenantSeatContext();

            // A ward has two open member seats, not three.
            expectRejectedByTenantDatabase(fn () => OfficeHolding::query()->create([
                'person_id' => $person->id,
                'position_key' => PositionKey::WardMemberOpen->value,
                'constituency_id' => $wardId,
                'seat_index' => 3,
                'start_date' => '2022-05-30',
            ]));
        });
    }, tenantWithPublishedWards());
});

it('refuses a mayor in a rural municipality', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $person = Person::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($person): void {
            [, $localLevelId] = tenantSeatContext();

            expectRejectedByTenantDatabase(fn () => OfficeHolding::query()->create([
                'person_id' => $person->id,
                'position_key' => PositionKey::Mayor->value,
                'constituency_id' => $localLevelId,
                'start_date' => '2022-05-30',
            ]));
        });
    }, tenantWithPublishedWards(2, LocalLevelType::RuralMunicipality));
});

it('will not let a seat be held and vacant at the same time', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        $person = Person::factory()->create();

        app(TenantManager::class)->run($tenant, function () use ($person): void {
            [$wardId] = tenantSeatContext();

            app(RecordOfficeHolding::class)->handle(
                personId: $person->id,
                positionKey: PositionKey::WardChair->value,
                constituencyId: $wardId,
                startDate: Carbon::parse('2022-05-30'),
            );

            expectRejectedByTenantDatabase(fn () => Vacancy::query()->create([
                'position_key' => PositionKey::WardChair->value,
                'constituency_id' => $wardId,
                'vacant_from' => '2023-01-01',
                'reason' => VacancyReason::Resignation,
            ]));
        });
    }, tenantWithPublishedWards());
});

it('records a reserved seat that nobody stood for', function (): void {
    // docs/02 §4.2: 123 local levels had no candidate for this seat in 2022.
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            [$wardId] = tenantSeatContext();

            $vacancy = app(RecordVacancy::class)->handle(
                positionKey: PositionKey::WardMemberDalitWoman->value,
                constituencyId: $wardId,
                vacantFrom: Carbon::parse('2022-05-30'),
                reason: VacancyReason::NoCandidate,
            );

            expect($vacancy->reason)->toBe(VacancyReason::NoCandidate);
        });
    }, tenantWithPublishedWards());
});

it('opens a vacancy when a holding ends, if asked', function (): void {
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

            app(EndOfficeHolding::class)->handle(
                $holding,
                Carbon::parse('2025-09-01'),
                HoldingEndReason::Resignation,
                recordVacancy: true,
            );

            $vacancy = Vacancy::query()->firstOrFail();

            expect($vacancy->reason)->toBe(VacancyReason::Resignation)
                ->and($vacancy->vacant_from->toDateString())->toBe('2025-09-01');
        });
    }, tenantWithPublishedWards());
});

it('refuses to end a holding twice', function (): void {
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

            app(EndOfficeHolding::class)->handle($holding, Carbon::parse('2024-01-01'), HoldingEndReason::TermEnd);

            expect(fn () => app(EndOfficeHolding::class)->handle(
                $holding->refresh(),
                Carbon::parse('2025-01-01'),
                HoldingEndReason::Removal,
            ))->toThrow(OfficesException::class);
        });
    }, tenantWithPublishedWards());
});

it('keeps a ward office on a ward and nowhere else', function (): void {
    withTenantDatabase(function (Tenant $tenant): void {
        app(TenantManager::class)->run($tenant, function (): void {
            [$wardId, $localLevelId] = tenantSeatContext();

            $office = WardOffice::query()->create([
                'ward_id' => $wardId,
                'address_ne' => 'वडा कार्यालय',
                'phone' => '025-580000',
                'email' => 'ward@example.test',
            ]);

            expect($office->ward->id)->toBe($wardId);

            expectRejectedByTenantDatabase(fn () => WardOffice::query()->create([
                'ward_id' => $localLevelId,
                'phone' => '025-580001',
            ]));
        });
    }, tenantWithPublishedWards());
});

<?php

declare(strict_types=1);

use App\Modules\Offices\Enums\ElectionMethod;
use App\Modules\Offices\Enums\PositionKey;
use App\Modules\Offices\Enums\SeatCategory;
use App\Modules\Offices\Models\Position;
use Database\Seeders\PositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PositionSeeder::class);
});

it('seeds every position a local level can have', function (): void {
    expect(Position::query()->count())->toBe(count(PositionKey::cases()));
});

it('gives a voter in an urban ward seven seats to fill', function (): void {
    // docs/02 §4.1: mayor, deputy mayor, ward chair, woman member,
    // Dalit woman member, and two open members.
    $localLevelSeats = Position::query()
        ->directlyElected()
        ->current()
        ->forLocalLevelType('sub_metropolitan_city')
        ->where('constituency_level', 'local_level')
        ->get()
        ->sum(fn (Position $p): int => $p->seatsIn('sub_metropolitan_city'));

    $wardSeats = Position::query()
        ->directlyElected()
        ->current()
        ->forLocalLevelType('sub_metropolitan_city')
        ->where('constituency_level', 'ward')
        ->get()
        ->sum(fn (Position $p): int => $p->seatsIn('sub_metropolitan_city'));

    expect($localLevelSeats)->toBe(2)
        ->and($wardSeats)->toBe(5)
        ->and($localLevelSeats + $wardSeats)->toBe(7);
});

it('gives a rural voter the same seven under rural names', function (): void {
    $rural = Position::query()
        ->directlyElected()
        ->current()
        ->forLocalLevelType('rural_municipality')
        ->get();

    $keys = $rural->pluck('key')->all();

    expect($rural->sum(fn (Position $p): int => $p->seatsIn('rural_municipality')))->toBe(7)
        ->and($keys)->toContain(PositionKey::Chairperson->value)
        ->and($keys)->toContain(PositionKey::ViceChairperson->value)
        ->and($keys)->not->toContain(PositionKey::Mayor->value);
});

it('keeps assembly-elected executive members out of the ballot', function (): void {
    $executiveMembers = Position::query()
        ->whereIn('key', [
            PositionKey::ExecutiveMemberWoman->value,
            PositionKey::ExecutiveMemberDalitMinority->value,
        ])->get();

    expect($executiveMembers)->toHaveCount(2)
        ->and($executiveMembers->pluck('election_method')->unique()->all())
        ->toBe([ElectionMethod::Indirect])
        ->and($executiveMembers->pluck('ballot_order')->filter()->all())->toBe([]);
});

it('seats fewer executive members in a rural municipality than an urban one', function (): void {
    // docs/02 §3.1: urban executives take 5 women and 3 Dalit/minority members,
    // rural ones 4 and 2.
    $women = Position::query()->findOrFail(PositionKey::ExecutiveMemberWoman->value);
    $dalitMinority = Position::query()->findOrFail(PositionKey::ExecutiveMemberDalitMinority->value);

    expect($women->seatsIn('municipality'))->toBe(5)
        ->and($women->seatsIn('rural_municipality'))->toBe(4)
        ->and($dalitMinority->seatsIn('municipality'))->toBe(3)
        ->and($dalitMinority->seatsIn('rural_municipality'))->toBe(2);
});

it('reserves exactly two ward-member seats and leaves them open', function (): void {
    $open = Position::query()->findOrFail(PositionKey::WardMemberOpen->value);

    expect($open->seatsIn('metropolitan_city'))->toBe(2)
        ->and($open->seat_category)->toBe(SeatCategory::Open)
        ->and($open->seat_category->isReserved())->toBeFalse();
});

it('orders the ballot the way a voter meets it', function (): void {
    $order = Position::query()
        ->directlyElected()
        ->forLocalLevelType('municipality')
        ->inBallotOrder()
        ->pluck('key')
        ->all();

    expect($order)->toBe([
        PositionKey::Mayor->value,
        PositionKey::DeputyMayor->value,
        PositionKey::WardChair->value,
        PositionKey::WardMemberWoman->value,
        PositionKey::WardMemberDalitWoman->value,
        PositionKey::WardMemberOpen->value,
    ]);
});

it('refuses a position whose seat counts do not cover the types it applies to', function (): void {
    expectRejectedByDatabase(fn () => Position::query()->create([
        'key' => 'broken_position',
        'title_ne' => 'त्रुटि',
        'title_en' => 'Broken',
        'body' => 'executive',
        'constituency_level' => 'local_level',
        'seat_category' => 'open',
        'election_method' => 'direct',
        'appointment_type' => 'elected_direct',
        'applies_to_local_level_types' => ['municipality', 'rural_municipality'],
        'seats_per_constituency' => ['municipality' => 1],
    ]));
});

it('refuses a position with no seats at all', function (): void {
    expectRejectedByDatabase(fn () => Position::query()->create([
        'key' => 'zero_seats',
        'title_ne' => 'शून्य',
        'title_en' => 'Zero',
        'body' => 'executive',
        'constituency_level' => 'local_level',
        'seat_category' => 'open',
        'election_method' => 'direct',
        'appointment_type' => 'elected_direct',
        'applies_to_local_level_types' => ['municipality'],
        'seats_per_constituency' => ['municipality' => 0],
    ]));
});

it('refuses a local level type that is not one of the four', function (): void {
    expectRejectedByDatabase(fn () => Position::query()->create([
        'key' => 'town_mayor',
        'title_ne' => 'सहर',
        'title_en' => 'Town',
        'body' => 'executive',
        'constituency_level' => 'local_level',
        'seat_category' => 'open',
        'election_method' => 'direct',
        'appointment_type' => 'elected_direct',
        'applies_to_local_level_types' => ['town'],
        'seats_per_constituency' => ['town' => 1],
    ]));
});

it('refuses an indirect position described as directly elected', function (): void {
    expectRejectedByDatabase(fn () => Position::query()->create([
        'key' => 'mismatched',
        'title_ne' => 'बेमेल',
        'title_en' => 'Mismatched',
        'body' => 'executive',
        'constituency_level' => 'local_level',
        'seat_category' => 'open',
        'election_method' => 'indirect',
        'appointment_type' => 'elected_direct',
        'applies_to_local_level_types' => ['municipality'],
        'seats_per_constituency' => ['municipality' => 1],
    ]));
});

it('is idempotent, so a deploy can re-run it', function (): void {
    $this->seed(PositionSeeder::class);

    expect(Position::query()->count())->toBe(count(PositionKey::cases()));
});

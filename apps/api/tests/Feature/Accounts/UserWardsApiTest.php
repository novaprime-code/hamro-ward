<?php

declare(strict_types=1);

use App\Modules\Accounts\Enums\WardRelationship;
use App\Modules\Accounts\Models\User;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
| The saved wards API (HW-E30-F02-T01; docs/12 §12). The rules themselves are
| covered by UserWardsTest against the actions; what is asserted here is the
| HTTP edge:
|   - a ward is named by slug path and number, never by a uuid
|   - a refusal arrives as something a screen can show, with its reason
|   - one account can never see or touch another's wards
*/

uses(RefreshDatabase::class);

/** A municipality with wards, addressable the way the API expects. */
function wardWorld(): AdminUnit
{
    $province = publishedUnit(['level' => 'province', 'slug' => 'koshi', 'name_ne' => 'कोशी', 'name_en' => 'Koshi']);
    $district = publishedUnit(['level' => 'district', 'slug' => 'sunsari', 'parent_id' => $province->id, 'name_ne' => 'सुनसरी', 'name_en' => 'Sunsari']);
    $localLevel = publishedUnit([
        'level' => 'local_level',
        'slug' => 'koshara',
        'parent_id' => $district->id,
        'name_ne' => 'कोशारा',
        'name_en' => 'Koshara',
        'local_level_type' => 'sub_metropolitan_city',
    ]);

    foreach ([1, 2, 3] as $number) {
        $ward = publishedUnit([
            'level' => 'ward',
            'slug' => (string) $number,
            'parent_id' => $localLevel->id,
            'ward_number' => $number,
            'name_ne' => "वडा {$number}",
            'name_en' => "Ward {$number}",
        ]);

        AdminUnitSlug::query()->create([
            'admin_unit_id' => $ward->id,
            'slug_path' => "koshi/sunsari/koshara/{$number}",
            'is_current' => true,
        ]);
    }

    return $localLevel;
}

/** @param array<string, mixed> $attributes */
function publishedUnit(array $attributes): AdminUnit
{
    return AdminUnit::factory()->create($attributes + ['valid_to' => null]);
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function saveWard(User $user, array $body): TestResponse
{
    return testCase()->actingAs($user)->postJson('/api/v1/me/wards', $body + [
        'relationship' => WardRelationship::PermanentAddress->value,
    ]);
}

beforeEach(function (): void {
    wardWorld();
});

it('saves a ward by its path and number, and makes the first one primary', function (): void {
    $user = User::factory()->create();

    $response = saveWard($user, ['slug_path' => 'koshi/sunsari/koshara', 'ward_number' => 2]);

    $response->assertCreated()
        ->assertJsonPath('data.0.ward_number', 2)
        ->assertJsonPath('data.0.slug_path', 'koshi/sunsari/koshara')
        ->assertJsonPath('data.0.local_level.name.ne', 'कोशारा')
        ->assertJsonPath('data.0.is_primary', true)
        ->assertJsonPath('data.0.relationship', 'permanent_address')
        ->assertJsonPath('meta.max_saved', 5)
        ->assertHeader('Cache-Control', 'no-store, private');

    // Not open: no tenant exists for this municipality in this test, and the
    // screen has to be able to say so rather than list a row that does nothing.
    $response->assertJsonPath('data.0.open', false);
});

it('refuses a ward number that does not exist, and a municipality that does not', function (): void {
    $user = User::factory()->create();

    saveWard($user, ['slug_path' => 'koshi/sunsari/koshara', 'ward_number' => 9])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ward_number');

    saveWard($user, ['slug_path' => 'koshi/sunsari/nowhere', 'ward_number' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ward_number');

    expect($user->wards()->count())->toBe(0);
});

it('refuses a relationship that is not one of the four', function (): void {
    $user = User::factory()->create();

    testCase()->actingAs($user)->postJson('/api/v1/me/wards', [
        'slug_path' => 'koshi/sunsari/koshara',
        'ward_number' => 1,
        'relationship' => 'landlord',
    ])->assertUnprocessable()->assertJsonValidationErrors('relationship');
});

it('passes the action’s own refusal through, cap and all', function (): void {
    $user = User::factory()->create();

    saveWard($user, ['slug_path' => 'koshi/sunsari/koshara', 'ward_number' => 1])->assertCreated();

    // The same ward with the same relationship twice is the action's
    // "already saved", not a new row.
    $again = saveWard($user, ['slug_path' => 'koshi/sunsari/koshara', 'ward_number' => 1]);

    $again->assertUnprocessable();
    expect($again->json('errors.ward_number.0'))->toContain('already');
    expect($user->wards()->count())->toBe(1);
});

it('will not make a workplace the main ward', function (): void {
    $user = User::factory()->create();

    testCase()->actingAs($user)->postJson('/api/v1/me/wards', [
        'slug_path' => 'koshi/sunsari/koshara',
        'ward_number' => 1,
        'relationship' => WardRelationship::Workplace->value,
    ])->assertCreated()->assertJsonPath('data.0.is_primary', false)
        ->assertJsonPath('data.0.can_be_primary', false);

    $saved = $user->wards()->sole();

    testCase()->actingAs($user)->putJson("/api/v1/me/wards/{$saved->id}/primary")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_primary');
});

it('moves the main ward, leaving exactly one', function (): void {
    $user = User::factory()->create();

    saveWard($user, ['slug_path' => 'koshi/sunsari/koshara', 'ward_number' => 1])->assertCreated();
    saveWard($user, [
        'slug_path' => 'koshi/sunsari/koshara',
        'ward_number' => 2,
        'relationship' => WardRelationship::TemporaryAddress->value,
    ])->assertCreated();

    $second = $user->wards()->where('ward_id', wardId(2))->sole();

    testCase()->actingAs($user)->putJson("/api/v1/me/wards/{$second->id}/primary")
        ->assertOk()
        // Primary first, so the screen's first row is the home ward.
        ->assertJsonPath('data.0.ward_number', 2)
        ->assertJsonPath('data.0.is_primary', true)
        ->assertJsonPath('data.1.is_primary', false);

    expect($user->wards()->where('is_primary', true)->count())->toBe(1);
});

it('removes a ward and leaves the account with no main one rather than guessing', function (): void {
    $user = User::factory()->create();

    saveWard($user, ['slug_path' => 'koshi/sunsari/koshara', 'ward_number' => 1])->assertCreated();
    $primary = $user->wards()->sole();

    saveWard($user, [
        'slug_path' => 'koshi/sunsari/koshara',
        'ward_number' => 2,
        'relationship' => WardRelationship::Workplace->value,
    ])->assertCreated();

    testCase()->actingAs($user)->deleteJson("/api/v1/me/wards/{$primary->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_primary', false);
});

it('keeps one account out of another’s wards', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    saveWard($theirs, ['slug_path' => 'koshi/sunsari/koshara', 'ward_number' => 1])->assertCreated();
    $theirWard = $theirs->wards()->sole();

    testCase()->actingAs($mine)->getJson('/api/v1/me/wards')->assertOk()->assertJsonCount(0, 'data');

    testCase()->actingAs($mine)->deleteJson("/api/v1/me/wards/{$theirWard->id}")->assertNotFound();
    testCase()->actingAs($mine)->putJson("/api/v1/me/wards/{$theirWard->id}/primary")->assertNotFound();

    expect($theirs->wards()->count())->toBe(1);
});

it('is closed to visitors and to staff sessions', function (): void {
    testCase()->getJson('/api/v1/me/wards')->assertUnauthorized();

    /*
     * A staff session does not merely lack saved wards here — on the public
     * host it is not a session at all. ConfigureAuthForHost points Sanctum at
     * the citizen guard, so the staff cookie authenticates nothing and the
     * request is an anonymous one. (The admin host is the mirror of this:
     * EnsureAdminHost and the staff guard, asserted in StaffSignInTest.)
     */
    testCase()->actingAs(StaffUser::factory()->create(), 'staff')
        ->getJson('/api/v1/me/wards')
        ->assertUnauthorized();
});

it('tells the header how many wards are saved and whether reporting is open', function (): void {
    $user = User::factory()->unverified()->create();

    testCase()->actingAs($user)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.saved_wards', 0)
        ->assertJsonPath('data.can_report', false);

    saveWard($user, ['slug_path' => 'koshi/sunsari/koshara', 'ward_number' => 1])->assertCreated();
    $user->markEmailAsVerified();

    testCase()->actingAs($user->refresh())->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.saved_wards', 1)
        ->assertJsonPath('data.can_report', true);
});

/** The admin unit id of one demo ward. */
function wardId(int $number): string
{
    return AdminUnitSlug::query()
        ->where('slug_path', "koshi/sunsari/koshara/{$number}")
        ->sole()
        ->admin_unit_id;
}

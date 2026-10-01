<?php

declare(strict_types=1);

use App\Modules\Accounts\Actions\RemoveUserWard;
use App\Modules\Accounts\Actions\SaveUserWard;
use App\Modules\Accounts\Enums\WardRelationship;
use App\Modules\Accounts\Exceptions\WardLimitReached;
use App\Modules\Accounts\Exceptions\WardNotSavable;
use App\Modules\Accounts\Models\User;
use App\Modules\Accounts\Models\UserWard;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Geography\Models\AdminUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A municipality with however many wards a test needs.
 *
 * @return list<AdminUnit>
 */
function wardsForAccount(int $count = 1): array
{
    $localLevel = AdminUnit::factory()->localLevel(LocalLevelType::Municipality)->published()->create();

    $wards = [];

    for ($number = 1; $number <= $count; $number++) {
        $wards[] = AdminUnit::factory()->ward($number)->childOf($localLevel)->published()->create();
    }

    return $wards;
}

// ---- The caps -------------------------------------------------------------

it('saves a ward and makes the first one primary', function (): void {
    // Asking somebody to choose a primary out of one is a question with a
    // single answer, so the first saved address answers it.
    [$ward] = wardsForAccount();
    $user = User::factory()->create();

    $saved = app(SaveUserWard::class)->handle($user, $ward->id, WardRelationship::PermanentAddress);

    expect($saved->is_primary)->toBeTrue()
        ->and($user->fresh()->primaryWard()?->id)->toBe($saved->id);
});

it('does not make a workplace primary on its own', function (): void {
    [$ward] = wardsForAccount();
    $user = User::factory()->create();

    $saved = app(SaveUserWard::class)->handle($user, $ward->id, WardRelationship::Workplace);

    expect($saved->is_primary)->toBeFalse()
        ->and($user->fresh()->primaryWard())->toBeNull();
});

it('refuses to make a workplace primary even when asked', function (): void {
    [$ward] = wardsForAccount();
    $user = User::factory()->create();

    expect(fn () => app(SaveUserWard::class)->handle(
        $user, $ward->id, WardRelationship::Workplace, makePrimary: true,
    ))->toThrow(WardNotSavable::class);
});

it('keeps exactly one primary ward when a new one is promoted', function (): void {
    $wards = wardsForAccount(2);
    $user = User::factory()->create();
    $save = app(SaveUserWard::class);

    $first = $save->handle($user, $wards[0]->id, WardRelationship::PermanentAddress);
    $second = $save->handle($user, $wards[1]->id, WardRelationship::TemporaryAddress, makePrimary: true);

    expect($user->wards()->where('is_primary', true)->count())->toBe(1)
        ->and($second->fresh()->is_primary)->toBeTrue()
        ->and($first->fresh()->is_primary)->toBeFalse();
});

it('allows the same ward under two different relationships', function (): void {
    // Living and working in one ward is ordinary, and the two are different
    // claims about standing to report.
    [$ward] = wardsForAccount();
    $user = User::factory()->create();
    $save = app(SaveUserWard::class);

    $save->handle($user, $ward->id, WardRelationship::PermanentAddress);
    $save->handle($user, $ward->id, WardRelationship::Workplace);

    expect($user->wards()->count())->toBe(2);
});

it('does not count a second relationship on a saved ward toward the 30-day cap', function (): void {
    /*
     * Found by running this against a real database. The cap counted ROWS, so
     * "I also work in the ward I already live in" spent one of the three — a
     * rule nobody wrote. The cap exists to stop someone collecting wards in
     * order to post into them, and a ward you already have gives no new reach.
     */
    $wards = wardsForAccount(4);
    $user = User::factory()->create();
    $save = app(SaveUserWard::class);

    $save->handle($user, $wards[0]->id, WardRelationship::PermanentAddress);
    $save->handle($user, $wards[1]->id, WardRelationship::Other);

    // Same ward again, different relationship — a row, but not a new ward.
    $save->handle($user, $wards[0]->id, WardRelationship::Workplace);

    // So the third DISTINCT ward is still allowed.
    expect($save->handle($user, $wards[2]->id, WardRelationship::Other)->exists)->toBeTrue();

    // And the fourth is not.
    expect(fn () => $save->handle($user, $wards[3]->id, WardRelationship::Other))
        ->toThrow(WardLimitReached::class);
});

it('refuses the same ward twice under one relationship', function (): void {
    [$ward] = wardsForAccount();
    $user = User::factory()->create();
    $save = app(SaveUserWard::class);

    $save->handle($user, $ward->id, WardRelationship::PermanentAddress);

    expect(fn () => $save->handle($user, $ward->id, WardRelationship::PermanentAddress))
        ->toThrow(WardNotSavable::class);
});

it('stops at five saved wards', function (): void {
    $wards = wardsForAccount(6);
    $user = User::factory()->create();

    // Backdated past the 30-day window, so this test measures the total cap and
    // not the addition rate — the two caps are different rules with different
    // remedies, and a test that trips the wrong one proves nothing.
    foreach (array_slice($wards, 0, 5) as $ward) {
        UserWard::factory()->forUser($user)->inWard($ward)->create([
            'created_at' => now()->subDays(60),
        ]);
    }

    expect(fn () => app(SaveUserWard::class)->handle(
        $user, $wards[5]->id, WardRelationship::Other,
    ))->toThrow(WardLimitReached::class);
});

it('stops at three wards added in 30 days, and says when that clears', function (): void {
    $wards = wardsForAccount(4);
    $user = User::factory()->create();

    foreach (array_slice($wards, 0, 3) as $ward) {
        UserWard::factory()->forUser($user)->inWard($ward)->create([
            'created_at' => now()->subDays(5),
        ]);
    }

    try {
        app(SaveUserWard::class)->handle($user, $wards[3]->id, WardRelationship::Other);
        $this->fail('the 30-day cap did not fire');
    } catch (WardLimitReached $e) {
        /*
         * The distinction the exception exists to carry: this cap clears on its
         * own, and the reply has to say when. "Limit reached" would leave a
         * citizen waiting for something that never happens.
         */
        expect($e->limit)->toBe('additions_per_30_days')
            ->and($e->clearsAt)->not->toBeNull();
    }
});

// ---- What a ward has to be ------------------------------------------------

it('refuses a municipality saved as if it were a ward', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->published()->create();
    $user = User::factory()->create();

    expect(fn () => app(SaveUserWard::class)->handle(
        $user, $localLevel->id, WardRelationship::PermanentAddress,
    ))->toThrow(WardNotSavable::class);
});

it('refuses a ward closed by a restructure', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->published()->create();
    $ward = AdminUnit::factory()->ward(1)->childOf($localLevel)->closed()->create();
    $user = User::factory()->create();

    expect(fn () => app(SaveUserWard::class)->handle(
        $user, $ward->id, WardRelationship::PermanentAddress,
    ))->toThrow(WardNotSavable::class);
});

it('saves a ward whose municipality is not onboarded yet', function (): void {
    /*
     * Deliberate (docs/12 §12.2). Refusing would tell a citizen their ward does
     * not exist, when the truth is that we have not opened there yet — and the
     * saved ward starts working on its own when the tenant goes live.
     */
    $localLevel = AdminUnit::factory()->localLevel()->create(); // unpublished, no tenant
    $ward = AdminUnit::factory()->ward(1)->childOf($localLevel)->create();
    $user = User::factory()->create();

    $saved = app(SaveUserWard::class)->handle($user, $ward->id, WardRelationship::PermanentAddress);

    expect($saved->exists)->toBeTrue();
});

// ---- The database holds the line on its own -------------------------------

it('enforces the caps in the database, not only in the action', function (): void {
    /*
     * docs/12 assigns these caps to the action layer. They are in the database
     * as well, because a cap that lives in one action is a cap the next code
     * path does not have — an import, an admin tool, a fixture.
     */
    $wards = wardsForAccount(6);
    $user = User::factory()->create();

    foreach (array_slice($wards, 0, 5) as $ward) {
        UserWard::factory()->forUser($user)->inWard($ward)->create([
            'created_at' => now()->subDays(60),
        ]);
    }

    expectRejectedByDatabase(fn () => UserWard::factory()
        ->forUser($user)->inWard($wards[5])->create(['created_at' => now()->subDays(60)]));
});

it('refuses two primary wards at the database level', function (): void {
    $wards = wardsForAccount(2);
    $user = User::factory()->create();

    UserWard::factory()->forUser($user)->inWard($wards[0])->primary()->create();

    expectRejectedByDatabase(fn () => UserWard::factory()
        ->forUser($user)->inWard($wards[1])->primary()->create());
});

it('refuses a saved row pointing at something that is not a ward', function (): void {
    $district = AdminUnit::factory()->district()->create();
    $user = User::factory()->create();

    expectRejectedByDatabase(fn () => UserWard::factory()
        ->forUser($user)->inWard($district)->create());
});

// ---- Removal ---------------------------------------------------------------

it('leaves no primary ward rather than promoting one', function (): void {
    /*
     * Promotion would be the platform deciding where somebody is from, on the
     * strength of row order. The account works without a primary until they say.
     */
    $wards = wardsForAccount(2);
    $user = User::factory()->create();
    $save = app(SaveUserWard::class);

    $primary = $save->handle($user, $wards[0]->id, WardRelationship::PermanentAddress);
    $save->handle($user, $wards[1]->id, WardRelationship::Workplace);

    app(RemoveUserWard::class)->handle($user, $primary->id);

    expect($user->fresh()->wards()->count())->toBe(1)
        ->and($user->fresh()->primaryWard())->toBeNull();
});

it('does not remove another account\'s saved ward', function (): void {
    [$ward] = wardsForAccount();
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    $saved = UserWard::factory()->forUser($theirs)->inWard($ward)->create();

    expect(app(RemoveUserWard::class)->handle($mine, $saved->id))->toBeFalse()
        ->and(UserWard::query()->whereKey($saved->id)->exists())->toBeTrue();
});

it('removes saved wards when the account goes', function (): void {
    [$ward] = wardsForAccount();
    $user = User::factory()->create();
    UserWard::factory()->forUser($user)->inWard($ward)->create();

    $user->delete();

    expect(UserWard::query()->count())->toBe(0);
});

// ---- The account itself ----------------------------------------------------

it('treats one address as one account whatever the capitalisation', function (): void {
    User::factory()->create(['email' => 'Nabin@example.com']);

    expectRejectedByDatabase(fn () => User::factory()->create(['email' => 'nabin@example.com']));
});

it('will not let an unverified or locked account report', function (): void {
    // Verification is what makes a report traceable to somebody who can be
    // contacted about it (docs/12 §12.3).
    expect(User::factory()->create()->canReport())->toBeTrue()
        ->and(User::factory()->unverified()->create()->canReport())->toBeFalse()
        ->and(User::factory()->locked()->create()->canReport())->toBeFalse()
        ->and(User::factory()->pendingDeletion()->create()->canReport())->toBeFalse();
});

it('keeps the password and two-factor secret out of serialised output', function (): void {
    $user = User::factory()->create();

    expect(array_keys($user->toArray()))
        ->not->toContain('password')
        ->not->toContain('two_factor_secret')
        ->not->toContain('remember_token');
});

it('measures the reporting cooldown from when the ward was saved', function (): void {
    // The rule that stops someone saving a ward purely in order to post into it
    // (docs/12 §12.4 rule 1).
    [$ward] = wardsForAccount();
    $user = User::factory()->create();

    $fresh = UserWard::factory()->forUser($user)->inWard($ward)->create(['created_at' => now()]);

    expect($fresh->isPastCooldown(72))->toBeFalse()
        ->and($fresh->isPastCooldown(0))->toBeTrue()
        ->and($fresh->usableFrom(72)->toIso8601String())
        ->toBe($fresh->created_at->copy()->addHours(72)->toIso8601String());
});

<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\User;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * HW-E30-F01-T01 acceptance: **citizen and staff sessions fully separate.**
 *
 * The threat is one person signed in twice in one browser — as a citizen on the
 * public host and as a moderator on the admin host — and the two sessions
 * reaching each other (docs/12 §16, "session confusion between citizen and
 * staff"). Three controls have to hold together:
 *
 *   1. different cookie names       hw_session / hw_staff_session
 *   2. host-only cookies            SESSION_DOMAIN null
 *   3. a different guard per host   web / staff
 *
 * ## Why these tests pass cookies around by hand
 *
 * Laravel's test client is not a browser: it keeps no cookie jar, so nothing is
 * carried from one request to the next unless the test carries it. Feature
 * tests normally get away with that because the session Store is a singleton
 * and survives in memory between requests inside one test.
 *
 * That is useless here, and worse than useless. ConfigureAuthForHost discards
 * the in-memory Store when the host changes — it has to, or a Store built for
 * one host would be reused for the other — so an alternating-host test loses
 * the session and every assertion about leakage passes for the one reason that
 * proves nothing: no session was carried at all.
 *
 * So each test below takes the Set-Cookie value off the login response and
 * sends it explicitly, which is what a browser does. It also sends BOTH
 * cookies to BOTH hosts, which is stricter than a browser would be with
 * host-only cookies: it is the shape a misconfigured SESSION_DOMAIN would
 * produce, and the separation has to survive it.
 */
beforeEach(function (): void {
    /*
     * The suite runs on the array driver, which stores nothing. These tests
     * are about whether a session survives from one request to the next, so
     * they need a store that stores. The sessions table is central and
     * RefreshDatabase rolls it back with everything else.
     */
    config(['session.driver' => 'database']);

    Route::middleware('web')->get('/_test/whoami', fn (): array => [
        'default' => auth()->id(),
        'web' => auth('web')->id(),
        'staff' => auth('staff')->id(),
    ]);
});

/**
 * Asserts the login set the cookie it should have, and returns the session id
 * to present on later requests.
 *
 * The id, not the Set-Cookie value. withCookie() hands Laravel's test client a
 * PLAIN value which it then encrypts and prefixes itself, exactly as
 * EncryptCookies expects to receive it. Passing the already-encrypted header
 * value instead gets it encrypted a second time, and what arrives decrypts to
 * a blob rather than to a session id — so the session silently does not resume
 * and the test appears to prove isolation that was never tested.
 */
function signInReturningSessionId(TestResponse $response, string $expectedCookie): string
{
    $response->assertSuccessful();

    $names = array_map(
        fn ($cookie): string => $cookie->getName(),
        $response->headers->getCookies(),
    );

    expect($names)->toContain($expectedCookie);

    return app('session.store')->getId();
}

function signInCitizen(User $citizen): string
{
    return signInReturningSessionId(
        test()->postJson('http://hamroward.test/login', [
            'email' => $citizen->email,
            'password' => 'password',
        ]),
        'hw_session',
    );
}

function signInStaff(StaffUser $staff): string
{
    return signInReturningSessionId(
        test()->postJson('http://admin.hamroward.test/login', [
            'email' => $staff->email,
            'password' => 'password',
        ]),
        'hw_staff_session',
    );
}

it('signs each account type in on its own host', function (): void {
    $citizen = User::factory()->create();
    $staff = StaffUser::factory()->create();

    $citizenSession = signInCitizen($citizen);
    $staffSession = signInStaff($staff);

    $this->withCookie('hw_session', $citizenSession)
        ->get('http://hamroward.test/_test/whoami')
        ->assertOk()
        ->assertExactJson(['default' => $citizen->id, 'web' => $citizen->id, 'staff' => null]);

    $this->withCookie('hw_staff_session', $staffSession)
        ->get('http://admin.hamroward.test/_test/whoami')
        ->assertOk()
        ->assertExactJson(['default' => $staff->id, 'web' => null, 'staff' => $staff->id]);
});

it('never yields a staff identity from a citizen session, however the cookie is named', function (): void {
    $citizen = User::factory()->create();
    $citizenSession = signInCitizen($citizen);

    /*
     * The citizen's session presented to the admin host under the STAFF cookie
     * name — a renamed cookie, or what a misconfigured SESSION_DOMAIN plus a
     * shared name would amount to.
     *
     * What this pins down, and the nuance worth knowing: session ids are
     * GLOBAL. There is one `sessions` table, so the admin host does load that
     * row and `auth('web')` does find the citizen in it. The thing that must
     * never happen is a STAFF identity, and it does not: `login_staff_…` is
     * not in that session, so the guard the admin host actually uses finds
     * nobody.
     *
     * Which is why the guard, not the session store, is the control here — and
     * why `auth.defaults.guard` is `staff` on that host and every staff route
     * is `auth:staff`. Nothing on the admin host may read the `web` guard.
     */
    $this->withCookie('hw_staff_session', $citizenSession)
        ->getJson('http://admin.hamroward.test/_test/whoami')
        ->assertOk()
        ->assertJson(['staff' => null]);
});

it('never yields a citizen identity from a staff session, however the cookie is named', function (): void {
    $staff = StaffUser::factory()->create();
    $staffSession = signInStaff($staff);

    $this->withCookie('hw_session', $staffSession)
        ->getJson('http://hamroward.test/_test/whoami')
        ->assertOk()
        ->assertJson(['web' => null]);
});

it('keeps both identities apart when one person holds both accounts', function (): void {
    /*
     * The realistic case: a moderator who is also a resident, in one browser,
     * with the same address on both accounts. Each host must answer with its
     * own account and must not see the other's — the whole premise being that
     * the moderator reviewing a report is not the citizen who filed it
     * (docs/12 §11.1).
     */
    $address = 'sunita@example.test';
    $citizen = User::factory()->create(['email' => $address]);
    $staff = StaffUser::factory()->create(['email' => $address]);

    expect($citizen->id)->not->toBe($staff->id);

    $citizenSession = signInCitizen($citizen);
    $staffSession = signInStaff($staff);

    $both = ['hw_session' => $citizenSession, 'hw_staff_session' => $staffSession];

    $this->withCookies($both)
        ->get('http://hamroward.test/_test/whoami')
        ->assertOk()
        ->assertExactJson(['default' => $citizen->id, 'web' => $citizen->id, 'staff' => null]);

    $this->withCookies($both)
        ->get('http://admin.hamroward.test/_test/whoami')
        ->assertOk()
        ->assertExactJson(['default' => $staff->id, 'web' => null, 'staff' => $staff->id]);
});

it('logs one identity out without ending the other session', function (): void {
    $address = 'sunita@example.test';
    $citizen = User::factory()->create(['email' => $address]);
    $staff = StaffUser::factory()->create(['email' => $address]);

    $citizenSession = signInCitizen($citizen);
    $staffSession = signInStaff($staff);

    expect($citizenSession)->not->toBe($staffSession);

    // Both sessions are stored, independently.
    $stored = fn (): array => DB::connection('central')->table('sessions')->pluck('id')->all();
    expect($stored())->toContain($citizenSession)->toContain($staffSession);

    /*
     * Sign the moderator out on their own host. Fortify's logout invalidates
     * the session it is holding — and must leave the other one alone, because
     * ending a moderator's shift is not a reason to sign a resident out of the
     * site, nor the reverse.
     *
     * Asserted against the session store rather than by re-presenting the
     * other cookie: within one PHP process the in-memory Store is discarded
     * when the host changes, so a follow-up request would be testing the test
     * client's lack of a cookie jar rather than the application.
     */
    $this->withCookie('hw_staff_session', $staffSession)
        ->postJson('http://admin.hamroward.test/logout')
        ->assertSuccessful();

    /*
     * The claim is only that the citizen's session is untouched. What exactly
     * becomes of the moderator's row is Laravel's own invalidate-and-
     * regenerate behaviour, and asserting on it here would be testing the
     * framework rather than the separation.
     */
    expect($stored())->toContain($citizenSession);
});

it('names each host cookie differently and scopes it to that host', function (): void {
    $citizen = User::factory()->create();

    $cookies = collect(
        $this->postJson('http://hamroward.test/login', [
            'email' => $citizen->email,
            'password' => 'password',
        ])->assertSuccessful()->headers->getCookies(),
    )->keyBy(fn ($cookie): string => $cookie->getName());

    expect($cookies)->toHaveKey('hw_session')
        ->and($cookies)->not->toHaveKey('hw_staff_session');

    /*
     * Host-only. A domain here — what SESSION_DOMAIN=.hamroward.np would
     * produce — would send this cookie to the admin host as well, leaving the
     * cookie name as the only thing keeping the two sessions apart
     * (docs/12 §11.2).
     */
    expect($cookies->get('hw_session')->getDomain())->toBeNull();
});

it('refuses a staff address at the citizen login', function (): void {
    $staff = StaffUser::factory()->create();

    $this->postJson('http://hamroward.test/login', [
        'email' => $staff->email,
        'password' => 'password',
    ])->assertStatus(422);
});

it('refuses a citizen address at the staff login', function (): void {
    $citizen = User::factory()->create();

    $this->postJson('http://admin.hamroward.test/login', [
        'email' => $citizen->email,
        'password' => 'password',
    ])->assertStatus(422);
});

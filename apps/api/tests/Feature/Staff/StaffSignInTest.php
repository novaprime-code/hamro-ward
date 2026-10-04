<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\User;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Staff\Actions\GrantTenantRole;
use App\Modules\Staff\Enums\TenantRole;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response;

/*
| Sign-in through Fortify, sessions through Sanctum (docs/12 §11, D-033;
| HW-E13-F01-T02, T03; HW-E30-F01-T01). Acceptance criteria:
|   - staff without confirmed 2FA cannot reach any staff endpoint
|   - 5 failures → 15 min lock; idle 30 min; absolute 12 h
|   - per-host session cookie and guard; staff routes 404 off the admin host
*/

uses(RefreshDatabase::class);

const ADMIN_HOST = 'http://admin.hamroward.test';
const PUBLIC_HOST = 'http://hamroward.test';
const STAFF_PASSWORD = 'correct horse battery staple';

/** The cookies a browser would be holding for the host in use. */
final class BrowserJar
{
    /** @var array<string, string> */
    public static array $cookies = [];
}

beforeEach(function (): void {
    config([
        'staff.admin_host' => 'admin.hamroward.test',
        'sanctum.stateful' => ['hamroward.test', 'admin.hamroward.test'],
    ]);
    BrowserJar::$cookies = [];
});

/**
 * One request as the browser makes it through the web tier: same-origin,
 * JSON, carrying and keeping cookies.
 *
 * @param  array<string, mixed>  $data
 * @return TestResponse<Response>
 */
function browse(string $method, string $path, array $data = [], string $host = ADMIN_HOST): TestResponse
{
    $response = testCase()
        ->withUnencryptedCookies(BrowserJar::$cookies)
        ->withHeaders(['Origin' => $host, 'Referer' => $host.'/'])
        ->json($method, $host.$path, $data);

    foreach ($response->headers->getCookies() as $cookie) {
        BrowserJar::$cookies[$cookie->getName()] = (string) $cookie->getValue();
    }

    return $response;
}

function enrolledStaff(): StaffUser
{
    $staff = StaffUser::factory()->create(['password' => STAFF_PASSWORD]);
    $staff->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey(32)),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recover-one', 'recover-two'], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $staff;
}

function totp(StaffUser $staff): string
{
    return app(Google2FA::class)->getCurrentOtp(Fortify::currentEncrypter()->decrypt((string) $staff->refresh()->two_factor_secret));
}

function staffSignIn(StaffUser $staff): void
{
    browse('POST', '/login', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertOk()->assertJson(['two_factor' => true]);
    browse('POST', '/two-factor-challenge', ['code' => totp($staff)])->assertNoContent();
}

it('chooses guard and session cookie by host, and hides staff routes elsewhere', function (): void {
    $staff = enrolledStaff();

    // A staff account cannot sign in on the public host: there the guard is the citizens'.
    browse('POST', '/login', ['email' => $staff->email, 'password' => STAFF_PASSWORD], PUBLIC_HOST)->assertUnprocessable();
    expect(array_key_exists('hw_session', BrowserJar::$cookies))->toBeTrue();
    browse('GET', '/api/v1/staff/me', [], PUBLIC_HOST)->assertNotFound();

    BrowserJar::$cookies = [];
    browse('POST', '/login', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertOk();
    expect(array_key_exists('hw_staff_session', BrowserJar::$cookies))->toBeTrue()
        ->and(array_key_exists('hw_session', BrowserJar::$cookies))->toBeFalse();

    config(['staff.admin_host' => '']);
    browse('GET', '/api/v1/staff/me')->assertNotFound();
});

it('signs a citizen in on the public host only, with the citizen guard', function (): void {
    $citizen = User::factory()->create(['password' => 'a citizen password']);

    browse('POST', '/login', ['email' => $citizen->email, 'password' => 'a citizen password'], ADMIN_HOST)->assertUnprocessable();

    BrowserJar::$cookies = [];
    browse('POST', '/login', ['email' => $citizen->email, 'password' => 'a citizen password'], PUBLIC_HOST)->assertOk();
    browse('GET', '/api/v1/me', [], PUBLIC_HOST)->assertOk()
        ->assertJsonPath('data.display_name', $citizen->display_name)
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('makes staff enrol two-factor first, and nothing else answers until they have', function (): void {
    $staff = StaffUser::factory()->create(['password' => STAFF_PASSWORD]);
    $tenant = Tenant::factory()->create();
    app(GrantTenantRole::class)->handle(StaffUser::factory()->operatorAdmin()->create(), $staff, $tenant, TenantRole::Moderator);

    browse('POST', '/login', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertOk()->assertJson(['two_factor' => false]);

    browse('GET', '/api/v1/staff/me')->assertForbidden()->assertJson(['two_factor_required' => true]);
    browse('PUT', '/user/profile-information', ['name' => 'New Name'])->assertForbidden();

    // Turning two-factor on asks for the password again, then works.
    browse('POST', '/user/two-factor-authentication')->assertStatus(423);
    browse('POST', '/user/confirm-password', ['password' => STAFF_PASSWORD])->assertCreated();
    browse('POST', '/user/two-factor-authentication')->assertOk();
    expect(browse('GET', '/user/two-factor-qr-code')->assertOk()->json('svg'))->toContain('<svg');

    browse('POST', '/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertUnprocessable();
    browse('POST', '/user/confirmed-two-factor-authentication', ['code' => totp($staff)])->assertOk();
    expect(browse('GET', '/user/two-factor-recovery-codes')->assertOk()->json())->toHaveCount(8);

    browse('GET', '/api/v1/staff/me')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.memberships.0.role', 'moderator')
        ->assertJsonPath('data.memberships.0.tenant_key', $tenant->tenant_key);
});

it('challenges for a code at sign-in, refuses a replayed one, and takes each recovery code once', function (): void {
    $staff = enrolledStaff();

    browse('POST', '/login', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertJson(['two_factor' => true]);
    browse('GET', '/api/v1/staff/me')->assertUnauthorized();
    browse('POST', '/two-factor-challenge', ['code' => '123456'])->assertUnprocessable();

    $code = totp($staff);
    browse('POST', '/two-factor-challenge', ['code' => $code])->assertNoContent();
    browse('GET', '/api/v1/staff/me')->assertOk();

    browse('POST', '/logout')->assertNoContent();
    browse('POST', '/login', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertOk();
    browse('POST', '/two-factor-challenge', ['code' => $code])->assertUnprocessable();

    browse('POST', '/two-factor-challenge', ['recovery_code' => 'recover-one'])->assertNoContent();
    browse('POST', '/logout');
    browse('POST', '/login', ['email' => $staff->email, 'password' => STAFF_PASSWORD]);
    browse('POST', '/two-factor-challenge', ['recovery_code' => 'recover-one'])->assertUnprocessable();
});

it('locks a staff account for 15 minutes after 5 failures, and says until when', function (): void {
    $staff = enrolledStaff();

    foreach (range(1, 4) as $attempt) {
        browse('POST', '/login', ['email' => $staff->email, 'password' => 'wrong'])->assertUnprocessable();
    }

    expect(browse('POST', '/login', ['email' => $staff->email, 'password' => 'wrong'])->assertStatus(423)->json('locked_until'))->not->toBeNull();
    browse('POST', '/login', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertStatus(423);

    testCase()->travel(16)->minutes();
    browse('POST', '/login', ['email' => $staff->email, 'password' => STAFF_PASSWORD])->assertOk();

    expect(AuditEvent::query()->where('action', 'staff_user.locked')->count())->toBe(1);
});

it('answers an unknown address exactly as a wrong password', function (): void {
    $staff = enrolledStaff();

    $unknown = browse('POST', '/login', ['email' => 'nobody@example.test', 'password' => STAFF_PASSWORD]);
    $wrong = browse('POST', '/login', ['email' => $staff->email, 'password' => 'wrong']);

    expect($unknown->status())->toBe($wrong->status())
        ->and($unknown->json('errors'))->toBe($wrong->json('errors'));
});

it('ends a staff session after 30 idle minutes', function (): void {
    staffSignIn(enrolledStaff());

    testCase()->travel(29)->minutes();
    browse('GET', '/api/v1/staff/me')->assertOk();

    testCase()->travel(31)->minutes();
    browse('GET', '/api/v1/staff/me')->assertUnauthorized();
});

it('ends a staff session 12 hours after sign-in, however active', function (): void {
    staffSignIn(enrolledStaff());

    foreach (range(1, 24) as $step) {
        testCase()->travel(29)->minutes();
        browse('GET', '/api/v1/staff/me')->assertOk();
    }

    testCase()->travel(29)->minutes();
    browse('GET', '/api/v1/staff/me')->assertUnauthorized();
});

it('creates the first operator admin from the console, without the password on the command line', function (): void {
    testCase()->artisan('hw:staff:create', ['email' => 'Admin@Example.test', '--operator-admin' => true, '--name' => 'First Admin'])
        ->expectsQuestion('Password (at least 12 characters)', STAFF_PASSWORD)
        ->expectsQuestion('Password again', STAFF_PASSWORD)
        ->assertSuccessful();

    $admin = StaffUser::query()->where('email', 'admin@example.test')->firstOrFail();

    expect($admin->isOperatorAdmin())->toBeTrue()
        ->and($admin->two_factor_confirmed_at)->toBeNull();

    testCase()->artisan('hw:staff:create', ['email' => 'short@example.test', '--name' => 'Short'])
        ->expectsQuestion('Password (at least 12 characters)', 'short')
        ->assertExitCode(2);
});

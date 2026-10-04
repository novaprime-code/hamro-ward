<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Staff\Actions\GrantTenantRole;
use App\Modules\Staff\Enums\TenantRole;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Staff\Support\TwoFactor;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response;

/*
| Staff sign-in (HW-E13-F01-T02, T03; docs/12 §11). Acceptance criteria:
|   - staff without confirmed 2FA cannot reach any staff endpoint
|   - 5 failures → 15 min lock; idle 30 min; absolute 12 h
|   - session cookie per host; staff routes 404 off the admin host
*/

uses(RefreshDatabase::class);

const ADMIN = 'http://admin.hamroward.test/api/v1/staff';
const PASSWORD = 'correct horse battery staple';

/** The cookies a browser on the admin host would be holding. */
final class StaffCookieJar
{
    /** @var array<string, string> */
    public static array $cookies = [];
}

beforeEach(function (): void {
    config(['staff.admin_host' => 'admin.hamroward.test']);
    StaffCookieJar::$cookies = [];
});

/**
 * One request as a browser would make it: the cookies from earlier responses
 * go back, the new ones are kept.
 *
 * @param  array<string, mixed>  $data
 * @return TestResponse<Response>
 */
function staffCall(string $method, string $path, array $data = [], string $base = ADMIN): TestResponse
{
    $response = testCase()->withUnencryptedCookies(StaffCookieJar::$cookies)->json($method, $base.$path, $data);

    foreach ($response->headers->getCookies() as $cookie) {
        StaffCookieJar::$cookies[$cookie->getName()] = (string) $cookie->getValue();
    }

    return $response;
}

function staffWith2fa(): StaffUser
{
    $staff = StaffUser::factory()->create(['password' => PASSWORD]);
    $staff->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();

    return $staff;
}

function currentCode(StaffUser $staff): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $staff->refresh()->getAttribute('two_factor_secret'));
}

function signIn(StaffUser $staff): void
{
    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD])->assertOk()->assertJson(['next' => 'challenge']);
    staffCall('POST', '/two-factor/challenge', ['code' => currentCode($staff)])->assertOk();
}

it('serves staff routes only on the admin host, with their own session cookie', function (): void {
    $staff = staffWith2fa();

    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD], 'http://hamroward.test/api/v1/staff')->assertNotFound();
    expect(StaffCookieJar::$cookies)->not->toHaveKey('hw_staff_session');

    // A browser keeps one cookie jar per host.
    StaffCookieJar::$cookies = [];
    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD])->assertOk();
    expect(array_key_exists('hw_staff_session', StaffCookieJar::$cookies))->toBeTrue()
        ->and(array_key_exists('hw_session', StaffCookieJar::$cookies))->toBeFalse();

    config(['staff.admin_host' => '']);
    staffCall('GET', '/me')->assertNotFound();
});

it('enrols two-factor at first sign-in, and nothing works before it is confirmed', function (): void {
    $staff = StaffUser::factory()->create(['password' => PASSWORD]);
    $tenant = Tenant::factory()->create();
    app(GrantTenantRole::class)->handle(StaffUser::factory()->operatorAdmin()->create(), $staff, $tenant, TenantRole::Moderator);

    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD])->assertOk()->assertJson(['next' => 'enrol']);

    // A password alone reaches nothing.
    staffCall('GET', '/me')->assertUnauthorized();

    $enrolment = staffCall('POST', '/two-factor/enrol')->assertOk();
    expect($enrolment->json('otpauth_url'))->toStartWith('otpauth://totp/')
        ->and($enrolment->json('qr_svg'))->toContain('<svg');

    staffCall('POST', '/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();

    $codes = staffCall('POST', '/two-factor/confirm', ['code' => currentCode($staff)])->assertOk()->json('recovery_codes');

    expect($codes)->toHaveCount(8)
        ->and($staff->refresh()->two_factor_confirmed_at)->not->toBeNull();

    staffCall('GET', '/me')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.email', $staff->email)
        ->assertJsonPath('data.memberships.0.role', 'moderator')
        ->assertJsonPath('data.memberships.0.tenant_key', $tenant->tenant_key);

    expect(AuditEvent::query()->where('action', 'staff_user.two_factor_enrolled')->exists())->toBeTrue();
});

it('refuses a signed-in session whose account has no confirmed two-factor', function (): void {
    $staff = StaffUser::factory()->create();

    testCase()->actingAs($staff, 'staff')->getJson(ADMIN.'/me')->assertUnauthorized();
});

it('asks for a code at later sign-ins, accepts each code once, and each recovery code once', function (): void {
    $staff = staffWith2fa();

    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD])->assertJson(['next' => 'challenge']);
    staffCall('POST', '/two-factor/challenge', ['code' => '123456'])->assertUnprocessable();

    $code = currentCode($staff);
    staffCall('POST', '/two-factor/challenge', ['code' => $code])->assertOk();
    staffCall('GET', '/me')->assertOk();

    // The same code, replayed into a fresh sign-in, is refused.
    staffCall('POST', '/logout')->assertOk();
    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD])->assertOk();
    staffCall('POST', '/two-factor/challenge', ['code' => $code])->assertUnprocessable();

    $recovery = app(TwoFactor::class)->newRecoveryCodes();
    $staff->forceFill(['two_factor_recovery_codes' => $recovery['stored']])->save();

    staffCall('POST', '/two-factor/challenge', ['recovery_code' => strtoupper($recovery['plain'][0])])->assertOk();
    staffCall('POST', '/logout');
    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD]);
    staffCall('POST', '/two-factor/challenge', ['recovery_code' => $recovery['plain'][0]])->assertUnprocessable();
});

it('locks an account for 15 minutes after 5 failures, and says until when', function (): void {
    $staff = staffWith2fa();

    foreach (range(1, 4) as $attempt) {
        staffCall('POST', '/login', ['email' => $staff->email, 'password' => 'wrong'])->assertUnprocessable();
    }

    $locked = staffCall('POST', '/login', ['email' => $staff->email, 'password' => 'wrong'])->assertStatus(423);
    expect($locked->json('locked_until'))->not->toBeNull();

    // The right password does not help while locked.
    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD])->assertStatus(423);

    testCase()->travel(16)->minutes();
    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD])->assertOk();

    expect(AuditEvent::query()->where('action', 'staff_user.locked')->count())->toBe(1);
});

it('answers an unknown address exactly as a wrong password', function (): void {
    $staff = staffWith2fa();

    $unknown = staffCall('POST', '/login', ['email' => 'nobody@example.test', 'password' => PASSWORD]);
    $wrong = staffCall('POST', '/login', ['email' => $staff->email, 'password' => 'wrong']);

    expect($unknown->status())->toBe($wrong->status())
        ->and($unknown->json('message'))->toBe($wrong->json('message'));
});

it('ends a session after 30 idle minutes', function (): void {
    signIn($staff = staffWith2fa());

    testCase()->travel(29)->minutes();
    staffCall('GET', '/me')->assertOk();

    testCase()->travel(31)->minutes();
    staffCall('GET', '/me')->assertUnauthorized();
});

it('ends a session 12 hours after sign-in, however active', function (): void {
    signIn(staffWith2fa());

    foreach (range(1, 24) as $step) {
        testCase()->travel(29)->minutes();
        staffCall('GET', '/me')->assertOk();
    }

    testCase()->travel(29)->minutes();
    staffCall('GET', '/me')->assertUnauthorized();
});

it('forgets a half-finished sign-in after 10 minutes', function (): void {
    $staff = staffWith2fa();

    staffCall('POST', '/login', ['email' => $staff->email, 'password' => PASSWORD])->assertOk();
    testCase()->travel(11)->minutes();

    staffCall('POST', '/two-factor/challenge', ['code' => currentCode($staff)])->assertStatus(409);
});

it('creates the first operator admin from the console, without the password on the command line', function (): void {
    testCase()->artisan('hw:staff:create', ['email' => 'Admin@Example.test', '--operator-admin' => true, '--name' => 'First Admin'])
        ->expectsQuestion('Password (at least 12 characters)', PASSWORD)
        ->expectsQuestion('Password again', PASSWORD)
        ->assertSuccessful();

    $admin = StaffUser::query()->where('email', 'admin@example.test')->firstOrFail();

    expect($admin->isOperatorAdmin())->toBeTrue()
        ->and($admin->two_factor_confirmed_at)->toBeNull();

    testCase()->artisan('hw:staff:create', ['email' => 'short@example.test', '--name' => 'Short'])
        ->expectsQuestion('Password (at least 12 characters)', 'short')
        ->assertExitCode(2);
});

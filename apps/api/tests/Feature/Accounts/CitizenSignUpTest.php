<?php

declare(strict_types=1);

use App\Modules\Accounts\Models\User;
use App\Modules\Accounts\Notifications\RegistrationAttempted;
use App\Modules\Accounts\Notifications\ResetPasswordLink;
use App\Modules\Accounts\Notifications\VerifyEmailAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
| Citizen sign-up and the links we mail (HW-E30-F01-T02; docs/09 §5.6,
| 12 §11.3). Acceptance criteria:
|   - the answer never says whether an address already has an account
|   - a verification link works without being signed in, on any device
|   - the links point at the web tier, not at this container
|   - Turnstile refuses a bad token and is skipped when unconfigured
*/

uses(RefreshDatabase::class);

const WEB = 'https://hamroward.test';

beforeEach(function (): void {
    config(['accounts.frontend_url' => WEB, 'turnstile.secret_key' => '']);
    Notification::fake();
});

/**
 * The sign-up body, with anything the test cares about overridden.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function signUp(array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Nabin Shrestha',
        'email' => 'nabin@example.com',
        'password' => 'a citizen password',
        'password_confirmation' => 'a citizen password',
        'preferred_locale' => 'ne',
    ], $overrides);
}

it('creates an account, mails a verification link and signs nobody in', function (): void {
    $response = $this->postJson('/register', signUp());

    $response->assertCreated()->assertJsonPath('status', 'verification_sent');

    $user = User::query()->where('email', 'nabin@example.com')->sole();
    expect($user->display_name)->toBe('Nabin Shrestha')
        ->and($user->preferred_locale)->toBe('ne')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->canReport())->toBeFalse();

    // No session: a cookie here would answer the question the enumeration
    // safety below exists to refuse.
    expect($this->app['auth']->guard('web')->check())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmailAddress::class);
});

it('answers a taken address exactly as it answers a free one, and tells the owner instead', function (): void {
    $existing = User::factory()->create(['email' => 'nabin@example.com', 'display_name' => 'The first Nabin']);

    $free = $this->postJson('/register', signUp(['email' => 'someone.else@example.com']));
    $taken = $this->postJson('/register', signUp(['email' => 'nabin@example.com']));

    expect($taken->status())->toBe($free->status())
        ->and($taken->json())->toBe($free->json());

    // Nothing was created or changed, and only the mailbox's owner is told.
    expect(User::query()->where('email', 'nabin@example.com')->count())->toBe(1)
        ->and($existing->refresh()->display_name)->toBe('The first Nabin');

    Notification::assertSentTo($existing, RegistrationAttempted::class);
    Notification::assertNotSentTo($existing, VerifyEmailAddress::class);
});

it('treats a differently cased address as the same account', function (): void {
    $existing = User::factory()->create(['email' => 'nabin@example.com']);

    $this->postJson('/register', signUp(['email' => 'Nabin@Example.com']))->assertCreated();

    expect(User::query()->count())->toBe(1);
    Notification::assertSentTo($existing, RegistrationAttempted::class);
});

it('refuses a sign-up that is missing or malformed', function (array $overrides, string $field): void {
    $this->postJson('/register', signUp($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(User::query()->count())->toBe(0);
})->with([
    'no name' => [['display_name' => ''], 'display_name'],
    'one letter' => [['display_name' => 'N'], 'display_name'],
    'not an address' => [['email' => 'nabin'], 'email'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'unconfirmed password' => [['password_confirmation' => 'something else'], 'password'],
    'a locale we do not have' => [['preferred_locale' => 'fr'], 'preferred_locale'],
]);

it('refuses a sign-up whose bot check fails, and lets one through when no keys are set', function (): void {
    config(['turnstile.secret_key' => 'a-secret']);
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
    ]);

    $this->postJson('/register', signUp(['turnstile_token' => 'a bad token']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('turnstile_token');

    expect(User::query()->count())->toBe(0);

    // No secret, no check: a developer's machine and CI have no keys.
    config(['turnstile.secret_key' => '']);
    $this->postJson('/register', signUp())->assertCreated();
});

it('verifies an address from the link alone, with nobody signed in', function (): void {
    $user = User::factory()->unverified()->create(['preferred_locale' => 'en']);

    $link = verificationLinkFor($user);

    expect($link)->toStartWith(WEB.'/email/verify/');

    $this->get($link)
        ->assertRedirect(WEB.'/en/account/verify?verified=1');

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and($user->canReport())->toBeTrue();
});

it('refuses a tampered or expired link without saying whether the account exists', function (): void {
    $user = User::factory()->unverified()->create();
    $link = verificationLinkFor($user);

    $this->get($link.'x')->assertForbidden();
    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();

    // A properly signed link — signed relatively, as the mailed one is —
    // whose hash is of a different address.
    $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1('someone.else@example.com'),
    ], absolute: false);

    $this->get($wrongHash)->assertRedirect(WEB.'/ne/account/verify?expired=1');
    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('sends the link again for an unverified address and answers the same for every address', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'nabin@example.com']);
    $verified = User::factory()->create(['email' => 'already@example.com']);

    $unknown = $this->postJson('/email/verification-notification', ['email' => 'nobody@example.com']);
    $pending = $this->postJson('/email/verification-notification', ['email' => 'nabin@example.com']);
    $done = $this->postJson('/email/verification-notification', ['email' => 'already@example.com']);

    foreach ([$pending, $done] as $response) {
        expect($response->status())->toBe($unknown->status())
            ->and($response->json())->toBe($unknown->json());
    }

    Notification::assertSentTo($user, VerifyEmailAddress::class);
    Notification::assertNotSentTo($verified, VerifyEmailAddress::class);
});

it('mails a reset link that points at the web tier and says nothing about who exists', function (): void {
    $user = User::factory()->create(['email' => 'nabin@example.com', 'preferred_locale' => 'en']);

    $known = $this->postJson('/forgot-password', ['email' => 'nabin@example.com']);
    $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@example.com']);

    $known->assertOk();
    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json())->toBe($known->json());

    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $url = $mail->actionUrl;

        // The old mail built its link from route('password.reset'), which this
        // application does not register — it threw instead of sending.
        expect($url)->toStartWith(WEB.'/en/account/reset-password?')
            ->and($url)->toContain('token='.$notification->token);

        return true;
    });
});

it('lets a citizen sign in with a password set from the reset link', function (): void {
    $user = User::factory()->create(['email' => 'nabin@example.com']);
    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/reset-password', [
        'token' => $token,
        'email' => 'nabin@example.com',
        'password' => 'a brand new password',
        'password_confirmation' => 'a brand new password',
    ])->assertOk();

    $this->post('/logout');

    $this->postJson('/login', ['email' => 'nabin@example.com', 'password' => 'a brand new password'])
        ->assertOk()
        ->assertJsonPath('two_factor', false);
});

it('stops a mailbox being used as a way of pestering someone', function (): void {
    User::factory()->unverified()->create(['email' => 'nabin@example.com']);

    // Six an hour per address, then the seventh is refused.
    for ($attempt = 1; $attempt <= 7; $attempt++) {
        $response = $this->postJson('/email/verification-notification', ['email' => 'nabin@example.com']);
    }

    expect($response->status())->toBe(429);
});

/** The link as it arrives in the inbox. */
function verificationLinkFor(User $user): string
{
    return (string) (new VerifyEmailAddress)->toMail($user)->actionUrl;
}

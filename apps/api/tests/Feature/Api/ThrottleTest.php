<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| The public read API is unauthenticated and, until now, uncapped. These tests
| pin the two ceilings, the fact that they are counted per caller, and the one
| exemption.
|
| Every test states the address it calls from. Laravel's test client would
| otherwise send 127.0.0.1, which the shipped configuration counts as internal,
| and a test that got the internal ceiling by accident while reading as though
| it tested the public one is worse than no test.
*/

const PUBLIC_CALLER = ['REMOTE_ADDR' => '203.0.113.10'];
const OTHER_PUBLIC_CALLER = ['REMOTE_ADDR' => '203.0.113.11'];
const WEB_TIER = ['REMOTE_ADDR' => '172.18.0.4'];

beforeEach(function (): void {
    // The limiter counts in the cache. The array store is rebuilt with the
    // application between tests, so this is insurance — but a throttle test
    // that depends on the order it ran in is a bad test.
    cache()->flush();
});

it('refuses a caller that goes past the public limit', function (): void {
    config()->set('security.throttle.public_read_per_minute', 3);

    foreach (range(1, 3) as $ignored) {
        $this->withServerVariables(PUBLIC_CALLER)->getJson('/api/v1/local-levels')->assertOk();
    }

    $this->withServerVariables(PUBLIC_CALLER)
        ->getJson('/api/v1/local-levels')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('tells a well-behaved caller how much room is left', function (): void {
    config()->set('security.throttle.public_read_per_minute', 10);

    $this->withServerVariables(PUBLIC_CALLER)
        ->getJson('/api/v1/local-levels')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '10')
        ->assertHeader('X-RateLimit-Remaining', '9');
});

it('counts each caller separately', function (): void {
    config()->set('security.throttle.public_read_per_minute', 1);

    $this->withServerVariables(PUBLIC_CALLER)->getJson('/api/v1/local-levels')->assertOk();

    // A different caller must not inherit the first one's spent limit.
    $this->withServerVariables(OTHER_PUBLIC_CALLER)->getJson('/api/v1/local-levels')->assertOk();

    $this->withServerVariables(PUBLIC_CALLER)->getJson('/api/v1/local-levels')->assertStatus(429);
});

it('gives the web tier a far higher ceiling than the public one', function (): void {
    /*
     * The point of the two-ceiling design. Every request the web tier makes
     * stands for many visitors, because the browser never reaches this
     * application directly — so if it shared the public limit, one busy minute
     * would take the site down for everyone at once.
     */
    config()->set('security.throttle.public_read_per_minute', 1);
    config()->set('security.throttle.internal_read_per_minute', 50);

    foreach (range(1, 6) as $ignored) {
        $this->withServerVariables(WEB_TIER)->getJson('/api/v1/local-levels')->assertOk();
    }
});

it('still stops the web tier if it runs away', function (): void {
    // The ceiling is high, not absent: a retry loop has to stop somewhere.
    config()->set('security.throttle.internal_read_per_minute', 2);

    $this->withServerVariables(WEB_TIER)->getJson('/api/v1/local-levels')->assertOk();
    $this->withServerVariables(WEB_TIER)->getJson('/api/v1/local-levels')->assertOk();
    $this->withServerVariables(WEB_TIER)->getJson('/api/v1/local-levels')->assertStatus(429);
});

it('leaves the health endpoint uncapped', function (): void {
    /*
     * A container health check and an uptime monitor call this on a schedule.
     * A monitor that receives a 429 reports an outage that is not happening,
     * and an orchestrator that receives one restarts a container that is fine.
     */
    config()->set('security.throttle.public_read_per_minute', 1);

    foreach (range(1, 5) as $ignored) {
        $this->withServerVariables(PUBLIC_CALLER)->getJson('/api/v1/health')->assertOk();
    }
});

it('keeps health detail on the internal network', function (): void {
    /*
     * Component detail names what is broken and how slow it is. The old check
     * admitted anything starting "172." — which is 172.0.0.0/8, mostly public
     * space — rather than the private 172.16.0.0/12.
     */
    $this->withServerVariables(['REMOTE_ADDR' => '172.217.16.1'])
        ->getJson('/api/v1/health?detail=1')
        ->assertOk()
        ->assertJsonMissingPath('checks');

    $this->withServerVariables(WEB_TIER)
        ->getJson('/api/v1/health?detail=1')
        ->assertOk()
        ->assertJsonPath('checks.central_database.status', 'ok');
});

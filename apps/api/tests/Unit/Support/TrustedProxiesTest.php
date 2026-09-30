<?php

declare(strict_types=1);

use App\Modules\Support\TrustedProxies;

/*
| TRUSTED_PROXIES decides which callers are allowed to say where a request came
| from. Getting it wrong is silent: the application keeps working, and the only
| symptom is that every address it records or counts is one the caller chose.
|
| So the parsing is pinned here rather than read in review.
*/

it('trusts everything only for the literal star', function (): void {
    expect(TrustedProxies::from('*'))->toBe('*')
        ->and(TrustedProxies::from(' * '))->toBe('*');
});

it('does not treat a star inside a list as trust-everything', function (): void {
    // '*,10.0.0.0/8' must not collapse to '*'. Symfony will simply never match
    // the bogus entry, which is the safe outcome.
    expect(TrustedProxies::from('*,10.0.0.0/8'))->toBe(['*', '10.0.0.0/8']);
});

it('splits a list of addresses and ranges', function (): void {
    expect(TrustedProxies::from('10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'))
        ->toBe(['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
});

it('tolerates the spacing a human leaves in an .env file', function (): void {
    expect(TrustedProxies::from(' 10.0.0.0/8 , 172.16.0.0/12 ,'))
        ->toBe(['10.0.0.0/8', '172.16.0.0/12']);
});

it('trusts nothing when the setting is empty or missing', function (): void {
    expect(TrustedProxies::from(''))->toBe([])
        ->and(TrustedProxies::from('   '))->toBe([])
        ->and(TrustedProxies::from(null))->toBe([]);
});

it('keeps IPv6 ranges intact', function (): void {
    expect(TrustedProxies::from('fd00::/8, ::1'))->toBe(['fd00::/8', '::1']);
});

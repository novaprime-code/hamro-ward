<?php

declare(strict_types=1);

/*
| Architecture rules (docs/06 §3.1). New rules are added with each module:
|   - controllers call one action or query and return a resource
|   - a module never writes another module's tables directly
|   - tenant models declare the tenant connection, central models the central one
*/

arch('modules declare strict types', function (): void {
    expect('App\Modules')
        ->toUseStrictTypes();
});

arch('no debugging statements ship', function (): void {
    expect(['dd', 'dump', 'var_dump', 'ray', 'die'])
        ->not->toBeUsed();
});

arch('no environment checks outside config', function (): void {
    expect('App\Modules')
        ->not->toUse(['env']);
});

arch('foundation modules stay independent', function (): void {
    expect('App\Modules\Support')
        ->not->toUse([
            'App\Modules\Geography',
            'App\Modules\Offices',
            'App\Modules\Issues',
        ]);
});

arch('the outbox flows one way: domain modules never reach into Publishing', function (): void {
    // Publishing reads Offices and Geography to build the index. If they
    // reached back, a change to a holding could depend on the index existing.
    expect(['App\Modules\Offices', 'App\Modules\Geography', 'App\Modules\Tenancy', 'App\Modules\Provenance'])
        ->not->toUse('App\Modules\Publishing');
});

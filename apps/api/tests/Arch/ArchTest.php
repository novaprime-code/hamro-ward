<?php

declare(strict_types=1);

/*
| Architecture rules (docs/06 §3.1). New rules are added with each module:
|   - controllers call one action or query and return a resource
|   - a module never writes another module's tables directly
|   - tenant models declare the tenant connection, central models the central one
*/

arch('modules declare strict types')
    ->expect('App\Modules')
    ->toUseStrictTypes();

arch('no debugging statements ship')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'die'])
    ->not->toBeUsed();

arch('no environment checks outside config')
    ->expect('App\Modules')
    ->not->toUse(['env']);

arch('foundation modules stay independent')
    ->expect('App\Modules\Support')
    ->not->toUse([
        'App\Modules\Geography',
        'App\Modules\Offices',
        'App\Modules\Issues',
    ]);

<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/*
| docs/12 §7: every concrete Eloquent model under app/Modules declares where its
| table lives — exactly one of UsesCentralConnection or UsesTenantConnection.
| Abstract bases (BaseSource, BaseSourceLink …) are shared by a central and a
| tenant subclass, so they declare neither.
*/
it('declares exactly one database connection trait on every module model', function (): void {
    $modulesPath = dirname(__DIR__, 2).'/app/Modules';

    $files = Finder::create()
        ->files()
        ->in($modulesPath)
        ->path('/(^|\/)Models\//')
        ->notPath('/Concerns\//')
        ->name('*.php');

    $checked = 0;

    foreach ($files as $file) {
        $class = 'App\\Modules\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            continue;
        }

        $traits = class_uses_recursive($class);
        $central = in_array(UsesCentralConnection::class, $traits, true);
        $tenant = in_array(UsesTenantConnection::class, $traits, true);

        if ((new ReflectionClass($class))->isAbstract()) {
            expect($central || $tenant)->toBeFalse(
                "{$class} is abstract and must leave the connection to its subclasses",
            );

            continue;
        }

        expect($central xor $tenant)->toBeTrue(
            "{$class} must use exactly one of UsesCentralConnection or UsesTenantConnection",
        );

        $checked++;
    }

    expect($checked)->toBeGreaterThan(0);
});

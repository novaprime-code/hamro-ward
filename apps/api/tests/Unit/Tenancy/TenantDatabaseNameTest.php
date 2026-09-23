<?php

declare(strict_types=1);

use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Support\Pg;
use App\Modules\Tenancy\Support\TenantDatabaseName;

it('builds the database name from the prefix and key', function (): void {
    expect(TenantDatabaseName::forKey('5f3a9c1e'))->toBe('hw_t_5f3a9c1e');
});

it('accepts valid tenant database names', function (string $name): void {
    TenantDatabaseName::assertValid($name);

    expect(true)->toBeTrue();
})->with(['hw_t_00000000', 'hw_t_abcdef01']);

it('rejects invalid or dangerous database names', function (string $name): void {
    expect(fn () => TenantDatabaseName::assertValid($name))->toThrow(TenancyException::class);
})->with([
    'hw_central',
    'hw_t_ABCDEF01',
    'hw_t_1234567',
    'hw_t_123456789',
    'hw_t_1234abcd"; DROP DATABASE hw_central; --',
    'postgres',
]);

it('rejects invalid keys', function (string $key): void {
    expect(fn () => TenantDatabaseName::forKey($key))->toThrow(TenancyException::class);
})->with(['', 'xyz', '5F3A9C1E', '5f3a9c1e0']);

it('quotes only safe identifiers', function (): void {
    expect(Pg::ident('hw_app'))->toBe('"hw_app"')
        ->and(fn () => Pg::ident('hw_app"; DROP ROLE x; --'))->toThrow(TenancyException::class)
        ->and(fn () => Pg::ident('Upper'))->toThrow(TenancyException::class);
});

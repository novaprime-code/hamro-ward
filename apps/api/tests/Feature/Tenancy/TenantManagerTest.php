<?php

declare(strict_types=1);

use App\Modules\Tenancy\Events\TenancyEnded;
use App\Modules\Tenancy\Events\TenancyInitialized;
use App\Modules\Tenancy\Exceptions\TenancyException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('generates an immutable key and database name', function (): void {
    $tenant = Tenant::factory()->create();

    expect($tenant->tenant_key)->toMatch('/^[0-9a-f]{8}$/')
        ->and($tenant->database_name)->toBe('hw_t_'.$tenant->tenant_key);

    $tenant->tenant_key = '00000000';

    expect(fn () => $tenant->save())->toThrow(TenancyException::class);
});

it('points both tenant connections at the tenant database and back', function (): void {
    $tenant = Tenant::factory()->create();
    $tenancy = app(TenantManager::class);

    $tenancy->initialize($tenant);

    expect(config('database.connections.tenant.database'))->toBe($tenant->database_name)
        ->and(config('database.connections.tenant_owner.database'))->toBe($tenant->database_name)
        ->and($tenancy->current()?->is($tenant))->toBeTrue();

    $tenancy->end();

    expect(config('database.connections.tenant.database'))->toBeNull()
        ->and(config('database.connections.tenant_owner.database'))->toBeNull()
        ->and($tenancy->initialized())->toBeFalse();
});

it('refuses tenants that are not active unless explicitly allowed', function (): void {
    $tenant = Tenant::factory()->maintenance()->create();
    $tenancy = app(TenantManager::class);

    expect(fn () => $tenancy->initialize($tenant))->toThrow(TenancyException::class);

    $tenancy->initialize($tenant, allowInactive: true);

    expect($tenancy->current()?->is($tenant))->toBeTrue();
});

it('restores the previous tenant after run()', function (): void {
    [$first, $second] = Tenant::factory()->count(2)->create()->all();
    $tenancy = app(TenantManager::class);

    $tenancy->initialize($first);

    $seen = $tenancy->run($second, fn (Tenant $tenant): string => $tenant->tenant_key);

    expect($seen)->toBe($second->tenant_key)
        ->and($tenancy->current()?->is($first))->toBeTrue()
        ->and(config('database.connections.tenant.database'))->toBe($first->database_name);
});

it('ends tenancy after run() when none was active before', function (): void {
    $tenant = Tenant::factory()->create();
    $tenancy = app(TenantManager::class);

    $tenancy->run($tenant, fn (): null => null);

    expect($tenancy->initialized())->toBeFalse();
});

it('refuses to use tenant models without an initialized tenant', function (): void {
    expect(fn () => TenantSetting::query()->count())->toThrow(TenancyException::class);
});

it('announces initialization and end', function (): void {
    Event::fake([TenancyInitialized::class, TenancyEnded::class]);

    $tenant = Tenant::factory()->create();

    app(TenantManager::class)->run($tenant, fn (): null => null);

    Event::assertDispatched(TenancyInitialized::class, fn (TenancyInitialized $e): bool => $e->tenant->is($tenant));
    Event::assertDispatched(TenancyEnded::class, fn (TenancyEnded $e): bool => $e->tenant->is($tenant));
});

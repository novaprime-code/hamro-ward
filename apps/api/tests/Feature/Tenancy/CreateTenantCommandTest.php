<?php

declare(strict_types=1);

use App\Modules\Geography\Actions\RefreshSlugPaths;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
});

it('onboards a local level by slug path', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create()->refresh();
    app(RefreshSlugPaths::class)->handle($localLevel);

    $path = (string) $localLevel->currentSlugPath()->value('slug_path');

    $this->artisan('hw:tenant:create', ['local_level' => $path])
        ->assertSuccessful()
        ->run();

    $tenant = Tenant::query()->where('admin_unit_id', $localLevel->id)->firstOrFail();

    try {
        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and($tenant->schema_version)->not->toBeNull()
            ->and($tenant->reference_version)->toBe(1)
            ->and($tenant->onboarded_at)->not->toBeNull()
            // onboarding never publishes anything by itself
            ->and($localLevel->refresh()->is_published)->toBeFalse();
    } finally {
        app(DropTenantDatabase::class)->handle($tenant);
    }
});

it('refuses anything that is not a current local level', function (): void {
    $ward = AdminUnit::factory()->ward()->create();
    $closed = AdminUnit::factory()->localLevel()->closed()->create();

    $this->artisan('hw:tenant:create', ['local_level' => $ward->id])->assertFailed()->run();
    $this->artisan('hw:tenant:create', ['local_level' => $closed->id])->assertFailed()->run();
    $this->artisan('hw:tenant:create', ['local_level' => 'no/such/place'])->assertFailed()->run();

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses to onboard the same local level twice', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create();

    $this->artisan('hw:tenant:create', ['local_level' => $localLevel->id])->assertSuccessful()->run();

    $tenant = Tenant::query()->where('admin_unit_id', $localLevel->id)->firstOrFail();

    try {
        $this->artisan('hw:tenant:create', ['local_level' => $localLevel->id])->assertFailed()->run();

        expect(Tenant::query()->where('admin_unit_id', $localLevel->id)->count())->toBe(1);
    } finally {
        app(DropTenantDatabase::class)->handle($tenant);
    }
});

it('leaves nothing behind when onboarding fails', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create();

    // A template that does not exist makes CREATE DATABASE fail
    config(['tenancy.template_database' => 'template_does_not_exist']);

    $this->artisan('hw:tenant:create', ['local_level' => $localLevel->id])->assertFailed()->run();

    expect(Tenant::query()->count())->toBe(0);
});

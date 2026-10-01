<?php

declare(strict_types=1);

use App\Modules\Geography\Actions\RefreshSlugPaths;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Actions\CreateTenant;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\ReferenceDataException;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
});

/**
 * The command takes a slug path — `hw:tenant:create koshi/sunsari/koshara`,
 * as docs/12 §6 and the operations runbook both document it. It does not take
 * a UUID: resolve() looks the path up in admin_unit_slugs and otherwise falls
 * back to a bare local-level slug. Earlier revisions of this file passed ids
 * under an argument named `local_level`, which matched neither.
 */
function slugPathFor(AdminUnit $unit): string
{
    app(RefreshSlugPaths::class)->handle($unit);

    return (string) $unit->currentSlugPath()->value('slug_path');
}

it('onboards a local level by slug path', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create()->refresh();

    $this->artisan('hw:tenant:create', ['path' => slugPathFor($localLevel), '--force' => true])
        ->assertSuccessful()
        ->run();

    $tenant = Tenant::query()->where('admin_unit_id', $localLevel->id)->firstOrFail();

    try {
        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and($tenant->schema_version)->not->toBeNull()
            ->and($tenant->reference_version)->toBe(1)
            ->and($tenant->onboarded_at)->not->toBeNull()
            // onboarding never publishes anything by itself (D-006)
            ->and($localLevel->refresh()->is_published)->toBeFalse();
    } finally {
        app(DropTenantDatabase::class)->handle($tenant);
    }
});

it('refuses a unit that is not a local level', function (): void {
    $ward = AdminUnit::factory()->ward()->create();

    // Resolves to the ward, then the action rejects it on level. Passing the
    // id instead would only have proved that a uuid is not a slug path.
    $this->artisan('hw:tenant:create', ['path' => slugPathFor($ward), '--force' => true])
        ->assertFailed()
        ->run();

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses a path that resolves to nothing', function (): void {
    $this->artisan('hw:tenant:create', ['path' => 'no/such/place', '--force' => true])
        ->assertFailed()
        ->run();

    expect(Tenant::query()->count())->toBe(0);
});

/**
 * Tested against the action rather than the command: RefreshSlugPaths only
 * records a path for a current unit, so a closed local level can never be
 * resolved by the command at all. Going through the command would exercise
 * resolve() a second time and leave the currency rule itself uncovered.
 */
it('refuses a closed local level', function (): void {
    $closed = AdminUnit::factory()->localLevel()->closed()->create()->refresh();

    expect(fn () => app(CreateTenant::class)->handle($closed))
        ->toThrow(ReferenceDataException::class);

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses to onboard the same local level twice', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create()->refresh();
    $path = slugPathFor($localLevel);

    $this->artisan('hw:tenant:create', ['path' => $path, '--force' => true])
        ->assertSuccessful()
        ->run();

    $tenant = Tenant::query()->where('admin_unit_id', $localLevel->id)->firstOrFail();

    try {
        $this->artisan('hw:tenant:create', ['path' => $path, '--force' => true])
            ->assertFailed()
            ->run();

        expect(Tenant::query()->where('admin_unit_id', $localLevel->id)->count())->toBe(1);
    } finally {
        app(DropTenantDatabase::class)->handle($tenant);
    }
});

/*
 * Renamed from "it leaves nothing behind when onboarding fails", which
 * asserted the tenant row was gone. CreateTenant documents the opposite and
 * means it: a failure moves the row to `maintenance` rather than deleting
 * it, because the database may be half-built and dropping it automatically
 * would destroy the evidence of why it failed. The operator decides.
 */
it('leaves a failed onboarding in maintenance for the operator', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create()->refresh();
    $path = slugPathFor($localLevel);

    // A template that does not exist makes CREATE DATABASE fail
    config(['tenancy.template_database' => 'template_does_not_exist']);

    $this->artisan('hw:tenant:create', ['path' => $path, '--force' => true])
        ->assertFailed()
        ->run();

    $tenant = Tenant::query()->where('admin_unit_id', $localLevel->id)->firstOrFail();

    expect($tenant->status)->toBe(TenantStatus::Maintenance)
        ->and($tenant->onboarded_at)->toBeNull();
});

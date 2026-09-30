<?php

declare(strict_types=1);

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\AdminUnitFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a full chain and records ancestors root-first', function (): void {
    $ward = AdminUnit::factory()->ward()->create()->refresh();

    $levels = $ward->ancestors()->map(fn (AdminUnit $unit): AdminLevel => $unit->level)->all();

    expect($levels)->toBe([AdminLevel::Country, AdminLevel::Province, AdminLevel::District, AdminLevel::LocalLevel])
        ->and($ward->ancestor_ids)->toHaveCount(4)
        ->and($ward->slug)->toBe((string) $ward->ward_number);
});

it('rejects a parent from the wrong level', function (): void {
    $district = AdminUnit::factory()->district()->create();

    expectRejectedByDatabase(fn () => AdminUnit::query()->create([
        'level' => AdminLevel::Ward,
        'parent_id' => $district->id,
        'ward_number' => 1,
        'slug' => '1',
        'name_en' => 'Ward 1',
    ]));
});

it('requires level-specific columns', function (): void {
    $district = AdminUnit::factory()->district()->create();
    $localLevel = AdminUnit::factory()->localLevel()->create();

    // local level without a type
    expectRejectedByDatabase(fn () => AdminUnit::query()->create([
        'level' => AdminLevel::LocalLevel,
        'parent_id' => $district->id,
        'slug' => 'no-type',
        'name_en' => 'No Type',
    ]));

    // ward without a number
    expectRejectedByDatabase(fn () => AdminUnit::query()->create([
        'level' => AdminLevel::Ward,
        'parent_id' => $localLevel->id,
        'slug' => '7',
        'name_en' => 'Ward 7',
    ]));

    // ward slug must be its number
    expectRejectedByDatabase(fn () => AdminUnit::query()->create([
        'level' => AdminLevel::Ward,
        'parent_id' => $localLevel->id,
        'ward_number' => 7,
        'slug' => 'seven',
        'name_en' => 'Ward 7',
    ]));

    // a ward cannot carry a local level type
    expectRejectedByDatabase(fn () => AdminUnit::query()->create([
        'level' => AdminLevel::Ward,
        'parent_id' => $localLevel->id,
        'ward_number' => 8,
        'local_level_type' => LocalLevelType::Municipality,
        'slug' => '8',
        'name_en' => 'Ward 8',
    ]));

    // at least one name
    expectRejectedByDatabase(fn () => AdminUnit::query()->create([
        'level' => AdminLevel::Ward,
        'parent_id' => $localLevel->id,
        'ward_number' => 9,
        'slug' => '9',
    ]));
});

it('allows only one current ward per number in a local level', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create();

    AdminUnit::factory()->ward(4)->childOf($localLevel)->closed()->create();
    AdminUnit::factory()->ward(4)->childOf($localLevel)->create();

    expectRejectedByDatabase(fn () => AdminUnit::factory()->ward(4)->childOf($localLevel)->create());

    expect($localLevel->children()->where('ward_number', 4)->count())->toBe(2);
});

it('never changes a unit\'s level or parent', function (): void {
    $ward = AdminUnit::factory()->ward()->create();
    $otherLocalLevel = AdminUnit::factory()->localLevel()->create();

    expectRejectedByDatabase(fn () => $ward->forceFill(['parent_id' => $otherLocalLevel->id])->save());

    $ward->refresh();

    expectRejectedByDatabase(fn () => $ward->forceFill(['level' => AdminLevel::LocalLevel, 'ward_number' => null])->save());
});

it('keeps current units under current parents', function (): void {
    $ward = AdminUnit::factory()->ward()->create();
    $localLevel = $ward->parent()->firstOrFail();

    // cannot close a local level that still has a current ward
    expectRejectedByDatabase(fn () => $localLevel->forceFill(['valid_to' => '2026-01-01'])->save());

    // cannot create a current ward under a closed local level
    $closed = AdminUnit::factory()->localLevel()->closed()->create();

    expectRejectedByDatabase(fn () => AdminUnit::factory()->ward(1)->childOf($closed)->create());
});

it('allows only one current root country', function (): void {
    $country = AdminUnitFactory::country();

    expect($country->level)->toBe(AdminLevel::Country);

    expectRejectedByDatabase(fn () => AdminUnit::query()->create([
        'level' => AdminLevel::Country,
        'parent_id' => null,
        'slug' => 'nepal-again',
        'name_en' => 'Nepal again',
    ]));
});

it('only lets a tenant point at a local level', function (): void {
    $ward = AdminUnit::factory()->ward()->create();

    expectRejectedByDatabase(fn () => Tenant::factory()->forLocalLevel($ward)->create());

    $tenant = Tenant::factory()->create();

    expect(AdminUnit::query()->findOrFail($tenant->admin_unit_id)->level)->toBe(AdminLevel::LocalLevel);
});

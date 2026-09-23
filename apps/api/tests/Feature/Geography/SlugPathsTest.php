<?php

declare(strict_types=1);

use App\Modules\Geography\Actions\RefreshSlugPaths;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds paths from province down for a whole subtree', function (): void {
    $ward = AdminUnit::factory()->ward(4)->create()->refresh();
    [, $province, $district, $localLevel] = $ward->ancestors()->all();

    app(RefreshSlugPaths::class)->handle($province);

    expect($ward->currentSlugPath()->value('slug_path'))
        ->toBe("{$province->slug}/{$district->slug}/{$localLevel->slug}/4");
});

it('keeps old paths for redirects when a slug changes', function (): void {
    $ward = AdminUnit::factory()->ward(2)->create()->refresh();
    $localLevel = $ward->parent()->firstOrFail();
    $refresh = app(RefreshSlugPaths::class);

    $refresh->handle($localLevel);
    $oldWardPath = (string) $ward->currentSlugPath()->value('slug_path');

    $localLevel->forceFill(['slug' => 'naya-naam'])->save();
    $refresh->handle($localLevel);

    $newWardPath = (string) $ward->currentSlugPath()->value('slug_path');

    expect($newWardPath)->toEndWith('/naya-naam/2')
        ->and(AdminUnitSlug::query()->where('slug_path', $oldWardPath)->value('is_current'))->toBeFalse()
        ->and(AdminUnitSlug::query()->where('admin_unit_id', $ward->id)->count())->toBe(2);
});

it('is idempotent', function (): void {
    $ward = AdminUnit::factory()->ward()->create()->refresh();
    $localLevel = $ward->parent()->firstOrFail();
    $refresh = app(RefreshSlugPaths::class);

    $refresh->handle($localLevel);
    $refresh->handle($localLevel);

    expect(AdminUnitSlug::query()->where('admin_unit_id', $ward->id)->count())->toBe(1);
});

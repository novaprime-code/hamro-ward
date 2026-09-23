<?php

declare(strict_types=1);

use App\Modules\Geography\Enums\AliasKind;
use App\Modules\Geography\Enums\AliasScript;
use App\Modules\Geography\Enums\CodeScheme;
use App\Modules\Geography\Enums\LineageEvent;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitAlias;
use App\Modules\Geography\Models\AdminUnitCode;
use App\Modules\Geography\Models\AdminUnitLineage;
use App\Modules\Geography\Support\NameNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('normalizes aliases on save and finds misspellings by trigram similarity', function (): void {
    $localLevel = AdminUnit::factory()->localLevel()->create();

    $alias = AdminUnitAlias::query()->create([
        'admin_unit_id' => $localLevel->id,
        'alias' => 'Namuna Nagarpalika',
        'script' => AliasScript::Latin,
        'kind' => AliasKind::Variant,
    ]);

    expect($alias->normalized)->toBe('namuna nagarpalika');

    $found = AdminUnitAlias::query()
        ->whereRaw('similarity(normalized, ?) > 0.3', [NameNormalizer::normalize('namuna nagarpalka')])
        ->pluck('admin_unit_id')
        ->all();

    expect($found)->toContain($localLevel->id);
});

it('keeps external codes unique per scheme and per unit', function (): void {
    $first = AdminUnit::factory()->localLevel()->create();
    $second = AdminUnit::factory()->localLevel()->create();

    AdminUnitCode::query()->create(['admin_unit_id' => $first->id, 'scheme' => CodeScheme::CbsCensus2021, 'code' => '10101']);

    expect(fn () => DB::connection('central')->transaction(fn () => AdminUnitCode::query()->create([
        'admin_unit_id' => $second->id, 'scheme' => CodeScheme::CbsCensus2021, 'code' => '10101',
    ])))->toThrow(QueryException::class);

    expect(fn () => DB::connection('central')->transaction(fn () => AdminUnitCode::query()->create([
        'admin_unit_id' => $first->id, 'scheme' => CodeScheme::CbsCensus2021, 'code' => '99999',
    ])))->toThrow(QueryException::class);
});

it('records lineage between distinct units only', function (): void {
    $old = AdminUnit::factory()->localLevel()->closed()->create();
    $new = AdminUnit::factory()->localLevel()->create();

    $link = AdminUnitLineage::query()->create([
        'predecessor_id' => $old->id,
        'successor_id' => $new->id,
        'event' => LineageEvent::Restructure2017,
        'effective_date' => '2017-03-10',
    ]);

    expect($link->successor()->firstOrFail()->is($new))->toBeTrue();

    expect(fn () => DB::connection('central')->transaction(fn () => AdminUnitLineage::query()->create([
        'predecessor_id' => $new->id,
        'successor_id' => $new->id,
        'event' => LineageEvent::Rename,
        'effective_date' => '2026-01-01',
    ])))->toThrow(QueryException::class);
});

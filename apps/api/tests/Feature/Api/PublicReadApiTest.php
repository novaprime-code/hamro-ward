<?php

declare(strict_types=1);

use App\Modules\Demo\Actions\SeedDemoData;
use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
    app(SeedDemoData::class)->handle();
});

afterEach(function (): void {
    Tenant::query()->get()->each(function (Tenant $tenant): void {
        $tenant->forceFill(['status' => TenantStatus::Archived])->save();
        app(DropTenantDatabase::class)->handle($tenant);
    });
});

const KOSHARA = 'koshi/sunsari/koshara';

it('lists the municipalities a visitor can open', function (): void {
    $response = $this->getJson('/api/v1/local-levels')->assertOk();

    expect($response->json('data'))->toHaveCount(4);

    $koshara = collect($response->json('data'))->firstWhere('slug_path', KOSHARA);

    expect($koshara['name']['ne'])->toBe('कोशारा उपमहानगरपालिका')
        ->and($koshara['type'])->toBe('sub_metropolitan_city')
        ->and($koshara['wards'])->toBe(20)
        ->and($koshara['district']['en'])->toBe('Sunsari');
});

it('hides a municipality whose tenant is not active', function (): void {
    // A municipality mid-maintenance must not be offered: a picker entry that
    // leads to a 503 reads as a broken site.
    Tenant::query()->firstWhere('admin_unit_id', DemoDataset::id('admin_unit', 'koshara'))
        ?->forceFill(['status' => TenantStatus::Maintenance])->save();

    $paths = collect($this->getJson('/api/v1/local-levels')->json('data'))->pluck('slug_path');

    expect($paths)->not->toContain(KOSHARA)->toHaveCount(3);
});

it('returns a municipality with its wards and leadership', function (): void {
    $response = $this->getJson('/api/v1/local-levels/'.KOSHARA)->assertOk();

    expect($response->json('data.wards'))->toHaveCount(20)
        ->and($response->json('data.leadership'))->toHaveCount(2)
        ->and($response->json('data.leadership.0.position_key'))->toBe('mayor')
        ->and($response->json('data.coverage.total'))->toBe(2);
});

it('uses rural position names in a rural municipality', function (): void {
    $keys = collect($this->getJson('/api/v1/local-levels/sudurpashchim/baitadi/sainli')->json('data.leadership'))
        ->pluck('position_key');

    expect($keys)->toContain('chairperson')->not->toContain('mayor');
});

it('returns both constituencies on a ward page', function (): void {
    $response = $this->getJson('/api/v1/wards/'.KOSHARA.'/1')->assertOk();

    $seats = collect($response->json('data.seats'));

    // Five ward seats plus the mayor and deputy (docs/02 §4.1).
    expect($seats)->toHaveCount(7)
        ->and($seats->where('constituency_level', 'ward'))->toHaveCount(5)
        ->and($seats->where('constituency_level', 'local_level'))->toHaveCount(2);
});

it('lists every seat, including the ones nothing is known about', function (): void {
    // Ward 4 in the demonstration has no data at all. The seats must still
    // appear, or the page tells a citizen their ward has fewer
    // representatives than it has.
    $seats = collect($this->getJson('/api/v1/wards/'.KOSHARA.'/4')->json('data.seats'))
        ->where('constituency_level', 'ward');

    expect($seats)->toHaveCount(5)
        ->and($seats->pluck('state')->unique()->all())->toBe(['not_verified'])
        ->and($seats->pluck('person')->filter()->all())->toBe([]);
});

it('reports a verified vacancy as vacant, with its reason', function (): void {
    $seat = collect($this->getJson('/api/v1/wards/'.KOSHARA.'/2')->json('data.seats'))
        ->firstWhere('position_key', 'ward_member_dalit_woman');

    expect($seat['state'])->toBe('vacant')
        ->and($seat['vacancy']['reason'])->toBe('no_candidate');
});

it('keeps an unsourced holder visible but unverified', function (): void {
    // Ward 3 has holders recorded with no source. The name is returned — we do
    // know something — but the state says it is not confirmed, and the client
    // must not render it as fact (FR-SRC-02).
    $seat = collect($this->getJson('/api/v1/wards/'.KOSHARA.'/3')->json('data.seats'))
        ->firstWhere('position_key', 'ward_chair');

    expect($seat['state'])->toBe('not_verified')
        ->and($seat['person'])->not->toBeNull();
});

it('returns the ward office when there is one', function (): void {
    $office = $this->getJson('/api/v1/wards/'.KOSHARA.'/1')->json('data.ward_office');

    expect($office['phone'])->not->toBeNull()
        ->and($office['office_hours']['ne'])->not->toBeNull();
});

it('404s an unknown municipality rather than leaking that it is unpublished', function (): void {
    $this->getJson('/api/v1/local-levels/koshi/sunsari/nowhere')->assertNotFound();
    $this->getJson('/api/v1/wards/koshi/sunsari/nowhere/1')->assertNotFound();
});

it('404s a ward number the municipality does not have', function (): void {
    $this->getJson('/api/v1/wards/sudurpashchim/baitadi/sainli/40')->assertNotFound();
});

it('503s a municipality in maintenance, so search engines keep its pages', function (): void {
    Tenant::query()->firstWhere('admin_unit_id', DemoDataset::id('admin_unit', 'koshara'))
        ?->forceFill(['status' => TenantStatus::Maintenance])->save();

    $this->getJson('/api/v1/wards/'.KOSHARA.'/1')->assertStatus(503);
});

it('never leaks one municipality into another', function (): void {
    // The single most important property of the tenancy design.
    $koshara = collect($this->getJson('/api/v1/wards/'.KOSHARA.'/1')->json('data.seats'))
        ->pluck('person.slug')->filter();

    $sainli = collect($this->getJson('/api/v1/wards/sudurpashchim/baitadi/sainli/1')->json('data.seats'))
        ->pluck('person.slug')->filter();

    expect($koshara->intersect($sainli))->toBeEmpty();
});

it('answers health without touching a tenant', function (): void {
    $this->getJson('/api/v1/health')->assertOk()->assertJson(['status' => 'ok']);
});

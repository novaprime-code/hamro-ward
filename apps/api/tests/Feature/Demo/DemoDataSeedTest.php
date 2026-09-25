<?php

declare(strict_types=1);

use App\Modules\Demo\Actions\SeedDemoData;
use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Demo\Exceptions\DemoDataException;
use App\Modules\Demo\Support\DemoGuard;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Offices\Enums\SeatState;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Queries\CurrentSeatsQuery;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Database\Seeders\PositionSeeder;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SourceTypeSeeder::class);
    $this->seed(PositionSeeder::class);
});

afterEach(function (): void {
    // Four real databases get created; leaving them behind would fill the
    // server with hw_t_* within a few runs.
    Tenant::query()->get()->each(function (Tenant $tenant): void {
        $tenant->forceFill(['status' => TenantStatus::Archived])->save();
        app(DropTenantDatabase::class)->handle($tenant);
    });
});

it('refuses to seed in production unless explicitly allowed', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(DemoGuard::class)->assertMaySeed())
        ->toThrow(DemoDataException::class);
});

it('refuses when a demonstration name collides with a real local level', function (): void {
    // Someone imports the real geography and one of our invented names turns
    // out to exist. The scheme has failed and the seeder must stop.
    $district = AdminUnit::factory()->district()->published()->create();

    AdminUnit::factory()
        ->localLevel(LocalLevelType::Municipality)
        ->childOf($district)
        ->published()
        ->create(['slug' => 'sonapur', 'name_ne' => 'सोनापुर नगरपालिका']);

    expect(fn () => app(DemoGuard::class)->assertMaySeed())
        ->toThrow(DemoDataException::class);
});

it('builds four local levels, one of each type', function (): void {
    app(SeedDemoData::class)->handle();

    $types = AdminUnit::query()
        ->where('level', AdminLevel::LocalLevel->value)
        ->get()
        ->map(fn (AdminUnit $unit): string => $unit->local_level_type->value)
        ->sort()
        ->values()
        ->all();

    expect($types)->toBe([
        'metropolitan_city',
        'municipality',
        'rural_municipality',
        'sub_metropolitan_city',
    ]);
});

it('creates the ward counts the dataset declares', function (): void {
    app(SeedDemoData::class)->handle();

    foreach (DemoDataset::localLevels() as $demo) {
        $wards = AdminUnit::query()
            ->where('parent_id', DemoDataset::id('admin_unit', $demo->key))
            ->where('level', AdminLevel::Ward->value)
            ->count();

        expect($wards)->toBe($demo->wards, "{$demo->nameEn} should have {$demo->wards} wards");
    }
});

it('puts every local level in its real district and province', function (): void {
    app(SeedDemoData::class)->handle();

    $koshara = AdminUnit::query()->findOrFail(DemoDataset::id('admin_unit', 'koshara'));
    $district = $koshara->parent;

    expect($district->name_en)->toBe('Sunsari')
        ->and($district->level)->toBe(AdminLevel::District)
        ->and($district->parent->name_en)->toBe('Koshi Province');
});

it('shares one province between local levels rather than duplicating it', function (): void {
    app(SeedDemoData::class)->handle();

    // Four local levels, four different provinces — but Nepal only once.
    expect(AdminUnit::query()->where('level', AdminLevel::Country->value)->count())->toBe(1)
        ->and(AdminUnit::query()->where('level', AdminLevel::Province->value)->count())->toBe(4);
});

it('names nobody after a real public figure', function (): void {
    // The mockups shipped with a real politician's name as the demonstration
    // ward chair. This is the test that stops it happening again.
    app(SeedDemoData::class)->handle();

    $forbidden = ['राम बहादुर थापा', 'शेरबहादुर देउवा', 'पुष्पकमल दाहाल', 'माधव', 'बाबुराम', 'झलनाथ'];

    $names = Person::query()->pluck('full_name_ne')->all();

    foreach ($names as $name) {
        foreach ($forbidden as $politician) {
            expect(str_contains((string) $name, $politician))->toBeFalse(
                "Demonstration person \"{$name}\" contains a real public figure's name",
            );
        }
    }
});

it('invents every party', function (): void {
    app(SeedDemoData::class)->handle();

    $real = ['नेपाली कांग्रेस', 'एमाले', 'माओवादी', 'Nepali Congress', 'CPN', 'UML'];

    foreach (Party::query()->get() as $party) {
        foreach ($real as $name) {
            expect(str_contains((string) $party->name_ne, $name))->toBeFalse()
                ->and(str_contains((string) $party->name_en, $name))->toBeFalse();
        }
    }
});

it('produces all three seat states in the primary local level', function (): void {
    app(SeedDemoData::class)->handle();

    $demo = DemoDataset::primary();
    $tenant = Tenant::query()
        ->where('admin_unit_id', DemoDataset::id('admin_unit', $demo->key))
        ->firstOrFail();

    $states = app(TenantManager::class)->run($tenant, function () use ($demo): array {
        $localLevelId = DemoDataset::id('admin_unit', $demo->key);

        return app(CurrentSeatsQuery::class)
            ->forLocalLevel($localLevelId)
            ->pluck('state')
            ->unique()
            ->values()
            ->all();
    });

    // A demonstration where everything is confirmed demonstrates a directory.
    expect($states)->toContain(SeatState::Held)
        ->and($states)->toContain(SeatState::Vacant)
        ->and($states)->toContain(SeatState::NotVerified);
});

it('records the unfilled reserved seat as verifiably vacant', function (): void {
    app(SeedDemoData::class)->handle();

    $demo = DemoDataset::primary();
    $tenant = Tenant::query()
        ->where('admin_unit_id', DemoDataset::id('admin_unit', $demo->key))
        ->firstOrFail();

    $seat = app(TenantManager::class)->run($tenant, function () use ($demo): mixed {
        $wardId = DemoDataset::id('admin_unit', $demo->key, 'ward', '2');

        return app(CurrentSeatsQuery::class)
            ->forConstituency($wardId)
            ->firstWhere('positionKey', 'ward_member_dalit_woman');
    });

    expect($seat->state)->toBe(SeatState::Vacant)
        ->and($seat->vacancyReason?->value)->toBe('no_candidate');
});

it('is idempotent, so a re-seed refreshes rather than duplicates', function (): void {
    app(SeedDemoData::class)->handle();

    $unitsAfterFirst = AdminUnit::query()->count();
    $peopleAfterFirst = Person::query()->count();

    app(SeedDemoData::class)->handle();

    expect(AdminUnit::query()->count())->toBe($unitsAfterFirst)
        ->and(Person::query()->count())->toBe($peopleAfterFirst)
        ->and(Tenant::query()->count())->toBe(4);
});

it('gives the same identifiers on every run and every machine', function (): void {
    // Deterministic ids are what make a screenshot taken today still match the
    // database after a re-seed tomorrow.
    expect(DemoDataset::id('admin_unit', 'koshara'))
        ->toBe(DemoDataset::id('admin_unit', 'koshara'))
        ->and(DemoDataset::id('admin_unit', 'koshara'))
        ->not->toBe(DemoDataset::id('admin_unit', 'sonapur'));
});

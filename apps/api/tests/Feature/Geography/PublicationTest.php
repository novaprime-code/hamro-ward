<?php

declare(strict_types=1);

use App\Modules\Geography\Actions\PublishAdminUnit;
use App\Modules\Geography\Exceptions\GeographyException;
use App\Modules\Geography\Models\AdminUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return list<AdminUnit> root-first chain ending with the ward
 */
function wardChain(): array
{
    $ward = AdminUnit::factory()->ward()->create()->refresh();

    return [...$ward->ancestors()->all(), $ward];
}

it('shows a ward only when it and every ancestor are published', function (): void {
    $chain = wardChain();
    $ward = end($chain);
    $publish = app(PublishAdminUnit::class);

    expect($ward->isPubliclyVisible())->toBeFalse();

    foreach ($chain as $unit) {
        $publish->publish($unit->refresh());
    }

    expect($ward->isPubliclyVisible())->toBeTrue()
        ->and(AdminUnit::query()->publiclyVisible()->whereKey($ward->id)->exists())->toBeTrue();

    // hiding the district hides everything below it
    $publish->unpublish($chain[2]->refresh());

    expect($ward->isPubliclyVisible())->toBeFalse()
        ->and($ward->refresh()->is_published)->toBeTrue();
});

it('refuses to publish below an unpublished ancestor and names it', function (): void {
    $chain = wardChain();
    $publish = app(PublishAdminUnit::class);

    $publish->publish($chain[0]); // country
    $publish->publish($chain[1]); // province

    $localLevel = $chain[3];

    expect(fn () => $publish->publish($localLevel))
        ->toThrow(GeographyException::class, $chain[2]->slug);
});

it('records when a unit was published', function (): void {
    $country = Database\Factories\AdminUnitFactory::country();

    $published = app(PublishAdminUnit::class)->publish($country);

    expect($published->is_published)->toBeTrue()
        ->and($published->published_at)->not->toBeNull();

    $hidden = app(PublishAdminUnit::class)->unpublish($published);

    expect($hidden->is_published)->toBeFalse()
        ->and($hidden->published_at)->toBeNull();
});

it('never publishes historical units', function (): void {
    $closed = AdminUnit::factory()->localLevel()->closed()->create();

    expect(fn () => app(PublishAdminUnit::class)->publish($closed))
        ->toThrow(GeographyException::class);
});

it('checks ancestors of a unit that was just created in PHP', function (): void {
    // No refresh(): ancestor_ids is written by the database trigger, not by PHP.
    $ward = AdminUnit::factory()->ward()->create();

    expect(fn () => app(PublishAdminUnit::class)->publish($ward))
        ->toThrow(GeographyException::class);
});

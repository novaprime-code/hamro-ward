<?php

declare(strict_types=1);

namespace App\Modules\Demo\Support;

use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Demo\Data\DemoLocalLevel;
use App\Modules\Demo\Exceptions\DemoDataException;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use Illuminate\Contracts\Foundation\Application;

/**
 * The two checks that stand between demonstration data and a real platform
 * (docs/13 §3.3, §4).
 *
 * **Production.** Seeding invented representatives into a live civic platform
 * is the worst failure this project has available to it, and it would happen
 * through a mistyped environment on a deploy, not through malice. So the
 * seeder refuses to run in production unless someone sets HW_ALLOW_DEMO_DATA
 * deliberately.
 *
 * **Name collisions.** The four demonstration names were checked against the
 * 753 real local levels by hand, once. Hands are not a control. Once the real
 * geography has been imported, this check runs against it on every seed: if a
 * demonstration name ever matches a real published local level, the whole
 * scheme has quietly turned back into the problem it was built to avoid, and
 * the seeder stops.
 */
final class DemoGuard
{
    public function __construct(private readonly Application $app) {}

    public function assertMaySeed(): void
    {
        $this->assertNotProduction();
        $this->assertNoNameCollisions();
    }

    private function assertNotProduction(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        // config/demo.php, not env() — see the note there, and ArchTest's
        // "no environment checks outside config".
        if (config('demo.allow_in_production') === true) {
            return;
        }

        throw DemoDataException::refusedInProduction();
    }

    /**
     * Compares against published, current local levels only. An unpublished
     * import in progress is not yet a claim about the world, and blocking on
     * it would make the seeder unusable exactly while geography is being
     * loaded.
     */
    private function assertNoNameCollisions(): void
    {
        $demoKeys = array_map(
            static fn (DemoLocalLevel $level): string => $level->key,
            DemoDataset::localLevels(),
        );

        $demoNames = array_map(
            static fn (DemoLocalLevel $level): string => $level->nameNe,
            DemoDataset::localLevels(),
        );

        $collisions = AdminUnit::query()
            ->where('level', AdminLevel::LocalLevel->value)
            ->whereNull('valid_to')
            ->where('is_published', true)
            ->whereNotIn('id', $this->demoUnitIds())
            ->where(function ($query) use ($demoKeys, $demoNames): void {
                $query->whereIn('slug', $demoKeys)->orWhereIn('name_ne', $demoNames);
            })
            ->get();

        if ($collisions->isNotEmpty()) {
            throw DemoDataException::nameCollision(
                $collisions->map(
                    static fn (AdminUnit $unit): string => self::label($unit),
                )->all(),
            );
        }
    }

    /**
     * A readable label for the error message.
     *
     * Written out rather than calling a display-name helper on the model: the
     * central AdminUnit has no such method, and this line only ever runs while
     * reporting a collision — the one moment when a second fatal error would
     * hide the thing being reported.
     */
    private static function label(AdminUnit $unit): string
    {
        $name = $unit->name_en ?: ($unit->name_ne ?: $unit->slug);

        return $name.' ('.$unit->slug.')';
    }

    /**
     * The demonstration's own units, excluded so that re-seeding does not
     * report the previous run as a collision with itself.
     *
     * @return list<string>
     */
    private function demoUnitIds(): array
    {
        return array_map(
            static fn (DemoLocalLevel $level): string => DemoDataset::id('admin_unit', $level->key),
            DemoDataset::localLevels(),
        );
    }
}

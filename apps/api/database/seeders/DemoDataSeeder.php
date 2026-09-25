<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Demo\Actions\SeedDemoData;
use Illuminate\Database\Seeder;

/**
 * `php artisan db:seed --class=DemoDataSeeder` — the same demonstration the
 * hw:demo:seed command builds, for environments that drive seeding through
 * Laravel rather than the console command (CI, a fresh local checkout).
 *
 * Deliberately NOT called from DatabaseSeeder. Reference data belongs in every
 * environment; invented representatives do not, and a demonstration that seeds
 * itself by default is one `php artisan db:seed` away from a live platform.
 */
final class DemoDataSeeder extends Seeder
{
    public function __construct(private readonly SeedDemoData $seed) {}

    public function run(): void
    {
        $result = $this->seed->handle(fn (string $line): mixed => $this->command?->info($line));

        $this->command?->info(sprintf(
            'Seeded %d local levels, %d wards and %d people.',
            $result['geography']['local_levels'],
            $result['geography']['wards'],
            $result['directory']['people'],
        ));
    }
}

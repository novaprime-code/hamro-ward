<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Central reference data. Module seeders are added as they arrive:
 *   positions catalogue      HW-E05-F01-T01
 *   BS calendar              HW-E03-F01 follow-up
 *   issue categories         HW-E11-F01-T01
 *   fixture tenants          HW-E29-F02-T01
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SourceTypeSeeder::class,
        ]);
    }
}

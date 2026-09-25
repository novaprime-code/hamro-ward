<?php

declare(strict_types=1);

namespace App\Modules\Demo\Console;

use App\Modules\Demo\Actions\DropDemoData;
use Illuminate\Console\Command;

/**
 * `hw:demo:drop` — retires the demonstration (docs/13 §6).
 *
 * Drops four databases, so it always asks, even with --force on the seeder
 * side. Geography is deliberately left in place: provinces and districts are
 * real and may by then carry real local levels.
 */
final class DropDemoCommand extends Command
{
    protected $signature = 'hw:demo:drop {--force : Skip the confirmation prompt}';

    protected $description = 'Drop the demonstration tenants and remove their people and parties';

    public function handle(DropDemoData $drop): int
    {
        $this->components->warn('This drops the four demonstration tenant databases. It cannot be undone.');

        if (! $this->option('force') && ! $this->confirm('Drop demonstration data?', false)) {
            $this->components->warn('Nothing was dropped.');

            return self::SUCCESS;
        }

        $result = $drop->handle();

        $this->components->twoColumnDetail('Tenants dropped', (string) $result['tenants']);
        $this->components->twoColumnDetail('People removed', (string) $result['people']);
        $this->components->twoColumnDetail('Parties removed', (string) $result['parties']);
        $this->newLine();
        $this->components->info('Demonstration retired. Provinces and districts were left in place.');

        return self::SUCCESS;
    }
}

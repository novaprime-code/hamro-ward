<?php

declare(strict_types=1);

namespace App\Modules\Demo\Console;

use App\Modules\Demo\Actions\SeedDemoData;
use App\Modules\Demo\Exceptions\DemoDataException;
use Illuminate\Console\Command;

/**
 * `hw:demo:seed` — builds the demonstration from nothing (docs/13).
 *
 * Creates four tenant databases, so it says what it is about to do and asks
 * first. The guard refuses outright in production.
 */
final class SeedDemoCommand extends Command
{
    protected $signature = 'hw:demo:seed {--force : Skip the confirmation prompt}';

    protected $description = 'Seed the four demonstration local levels, their tenants, seats and evidence';

    public function handle(SeedDemoData $seed): int
    {
        $this->components->warn('Demonstration data: invented people, parties and municipalities.');
        $this->line('  Four tenant databases will be created or refreshed.');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Continue?', true)) {
            $this->components->warn('Nothing was seeded.');

            return self::SUCCESS;
        }

        try {
            /*
             * Each line is printed as the step STARTS, so the last line on the
             * screen is the step that failed.
             *
             * This used to call components->task($line, null). With a null
             * callback that prints the label and "DONE" immediately, before any
             * of the work runs — so a fatal error appeared under a step marked
             * DONE, and the step that actually failed looked like the next one.
             * A progress marker that cannot fail is worse than none.
             */
            $result = $seed->handle(fn (string $line): mixed => $this->line("  <fg=gray>›</> {$line}"));
        } catch (DemoDataException $exception) {
            $this->newLine();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            'Geography',
            sprintf(
                '%d provinces · %d districts · %d local levels · %d wards',
                $result['geography']['provinces'],
                $result['geography']['districts'],
                $result['geography']['local_levels'],
                $result['geography']['wards'],
            ),
        );
        $this->components->twoColumnDetail(
            'Directory',
            "{$result['directory']['people']} people · {$result['directory']['parties']} parties",
        );

        foreach ($result['tenants'] as $key => $tenant) {
            $this->components->twoColumnDetail(
                "{$key} ({$tenant['tenant_key']})",
                sprintf(
                    '%d holdings · %d vacancies · %d ward offices',
                    $tenant['holdings'],
                    $tenant['vacancies'],
                    $tenant['ward_offices'],
                ),
            );
        }

        $this->newLine();
        $this->components->info('Demonstration data seeded.');

        return self::SUCCESS;
    }
}
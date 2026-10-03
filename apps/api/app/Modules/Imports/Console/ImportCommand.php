<?php

declare(strict_types=1);

namespace App\Modules\Imports\Console;

use App\Modules\Imports\Actions\RunImport;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Support\ImportReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `hw:import {directory} [--tenant=path] [--dry-run]` (HW-E06-F02-T01).
 *
 * The workflow it serves (docs/06 §26 DF1): export the sheet to CSV, dry-run,
 * have a second person read the report (D-002), then run it for real. So the
 * report is always written to a file — it is the thing that gets reviewed —
 * and the real run asks for confirmation unless told not to.
 *
 * Exit code: 0 clean (dry run or imported), 1 refused. A CI job or a runbook
 * step can rely on that.
 */
final class ImportCommand extends Command
{
    protected $signature = 'hw:import
                            {directory : Folder holding the exported CSV files (docs/05 §13)}
                            {--tenant= : Slug path of the municipality the tenant files belong to, e.g. koshi/sunsari/example}
                            {--dry-run : Check everything and write the report; change nothing}
                            {--report= : Where to write the report (default: storage/app/imports/)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Import a data sheet exported as CSV: validate, report, and upsert idempotently';

    private const ERRORS_SHOWN = 20;

    public function handle(RunImport $import): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenant = $this->option('tenant') === null ? null : (string) $this->option('tenant');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm(
            'Import for real? Run with --dry-run first and have the report reviewed (D-002).',
            false,
        )) {
            $this->components->warn('Nothing was imported.');

            return self::SUCCESS;
        }

        try {
            $report = $import->handle((string) $this->argument('directory'), $tenant, $dryRun);
        } catch (ImportException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = $this->writeReport($report);

        foreach ($report->counts() as $entity => $count) {
            $this->components->twoColumnDetail($entity, sprintf(
                '%d created · %d updated · %d unchanged',
                $count['created'],
                $count['updated'],
                $count['unchanged'],
            ));
        }

        foreach (array_slice($report->errors(), 0, self::ERRORS_SHOWN) as $error) {
            $this->line('  <fg=red>✗</> '.$report->formatLocation($error).': '.$error['message']);
        }

        if (count($report->errors()) > self::ERRORS_SHOWN) {
            $this->line('  … and '.(count($report->errors()) - self::ERRORS_SHOWN).' more in the report.');
        }

        if ($report->warnings() !== []) {
            $this->components->warn(count($report->warnings()).' warning(s) — read them in the report before importing.');
        }

        $this->components->twoColumnDetail('Report', $path);
        $this->newLine();

        if ($report->hasErrors()) {
            $this->components->error(count($report->errors()).' problem(s). Nothing was written.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->components->info('Dry run clean. Nothing was written.');
        } elseif ($report->changes() === 0) {
            $this->components->info('Already up to date. Nothing changed.');
        } else {
            $this->components->info("Imported. Run {$report->runId}; every change is in audit_events under that request_id.");
        }

        return self::SUCCESS;
    }

    private function writeReport(ImportReport $report): string
    {
        $path = $this->option('report') !== null
            ? (string) $this->option('report')
            : storage_path(sprintf(
                'app/imports/%s-%s.md',
                $report->startedAt->format('Ymd-His'),
                $report->dryRun ? 'dry-run' : 'import',
            ));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $report->toMarkdown());

        return $path;
    }
}

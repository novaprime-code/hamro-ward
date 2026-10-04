<?php

declare(strict_types=1);

namespace App\Modules\Imports\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Support\CsvReader;
use App\Modules\Imports\Support\ImportContext;
use App\Modules\Imports\Support\ImportFile;
use App\Modules\Imports\Support\ImportReport;
use App\Modules\Imports\Support\ImportRow;
use App\Modules\Publishing\Jobs\DispatchTenantOutbox;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * `hw:import` end to end (HW-E06-F02-T01, docs/05 §13, docs/06 §26 DF1).
 *
 * One transaction per database, central first, then the tenant (§13). Both
 * stay open until every row of every file has been checked, and both commit
 * only if nothing was refused. A dry run is the same run, rolled back at the
 * end: it exercises every check the real run does — including the database's
 * own constraints and triggers, which a separate "validate only" pass would
 * have to reimplement and would get subtly wrong.
 *
 * Commit order is central, then tenant. If the tenant commit were ever to fail
 * after central succeeded, what remains is people and parties no holding yet
 * refers to: harmless, and fixed by re-running. The other order could leave a
 * municipality pointing at people who do not exist.
 *
 * Every holding it changes writes an outbox event in the tenant transaction,
 * and a committed tenant import queues the drain that applies them to the
 * central index (docs/12 §4.3).
 *
 * Not done here yet, and named so nobody assumes otherwise: the revalidation
 * job — HW-E08-F01-T04, which depends on this task.
 */
final class RunImport
{
    public function __construct(
        private readonly CsvReader $reader,
        private readonly ImportCentralRows $central,
        private readonly ImportTenantRows $tenant,
        private readonly TenantManager $tenancy,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $directory, ?string $tenantPath = null, bool $dryRun = true): ImportReport
    {
        $directory = rtrim($directory, '/');

        if (! is_dir($directory)) {
            throw ImportException::directoryMissing($directory);
        }

        $files = $this->files($directory);
        $tenant = $tenantPath === null ? null : $this->tenantAt(trim($tenantPath, '/'));

        $this->assertRouting($files, $tenant);

        $report = new ImportReport((string) Str::uuid(), $directory, $dryRun, $tenantPath === null ? null : trim($tenantPath, '/'), Carbon::now());
        $rows = [];

        foreach ($files as $file) {
            $path = $directory.'/'.$file->value;
            $rows[$file->value] = $this->reader->read($path, $file, $report);
            $report->file($file, count($rows[$file->value]), (string) hash_file('sha256', $path));
        }

        $context = new ImportContext($report, $tenant, $this->audit);

        // The tenant, when there is one, is initialized for the whole run: the
        // central half also looks into it, to stop a source_ref being loaded
        // as a national document in one run and a local one in another.
        $tenant === null
            ? $this->transact($context, $rows, null)
            : $this->tenancy->run($tenant, fn () => $this->transact(
                $context,
                $rows,
                DB::connection((string) config('tenancy.tenant_connection')),
            ), allowInactive: true);

        return $report;
    }

    /** @param  array<string, list<ImportRow>>  $rows */
    private function transact(ImportContext $context, array $rows, ?Connection $tenant): void
    {
        $central = DB::connection((string) config('tenancy.central_connection'));

        $central->beginTransaction();
        $tenant?->beginTransaction();

        try {
            $this->central->handle($context, $rows);

            if ($tenant !== null) {
                $this->tenant->handle($context, $rows);
            }

            $this->finish($context->report, $central, $tenant);

            // After the commit, never before: a drain queued inside the
            // transaction could run before the events it is meant to read exist.
            if ($context->tenant !== null && $context->report->committed() && $context->report->changes() > 0) {
                DispatchTenantOutbox::dispatch((string) $context->tenant->getKey());
            }
        } catch (Throwable $exception) {
            // A bug, not a data problem — data problems are reported per row.
            // Whatever ran is undone in both databases.
            if ($tenant !== null && $tenant->transactionLevel() > 0) {
                $tenant->rollBack();
            }

            if ($central->transactionLevel() > 0) {
                $central->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Commits only a clean real run. `import.completed` is written inside the
     * transactions it describes — and only when something changed, so that
     * re-running the same sheet leaves the database exactly as it was.
     */
    private function finish(ImportReport $report, Connection $central, ?Connection $tenant): void
    {
        $commit = ! $report->dryRun && ! $report->hasErrors();

        if ($commit && $report->changes() > 0) {
            $this->audit->central(
                ActorType::Importer,
                'import.completed',
                changes: $report->summary(),
                requestId: $report->runId,
            );

            if ($tenant !== null) {
                $this->audit->tenant(
                    ActorType::Importer,
                    'import.completed',
                    changes: $report->summary(),
                    requestId: $report->runId,
                );
            }
        }

        if ($commit) {
            $central->commit();
            $tenant?->commit();
        } else {
            $central->rollBack();
            $tenant?->rollBack();
        }

        $report->markCommitted($commit);
    }

    /** @return list<ImportFile> in processing order */
    private function files(string $directory): array
    {
        $present = array_map('basename', glob($directory.'/*.csv') ?: []);
        $known = array_map(fn (ImportFile $file): string => $file->value, ImportFile::cases());
        $unknown = array_values(array_diff($present, $known));

        if ($unknown !== []) {
            throw ImportException::unknownFiles($unknown);
        }

        $files = array_values(array_filter(ImportFile::cases(), fn (ImportFile $file): bool => in_array($file->value, $present, true)));

        if ($files === []) {
            throw ImportException::nothingToImport($directory);
        }

        return $files;
    }

    /** @param  list<ImportFile>  $files */
    private function assertRouting(array $files, ?Tenant $tenant): void
    {
        if ($tenant !== null && in_array(ImportFile::AdminUnits, $files, true)) {
            throw ImportException::geographyWithTenant();
        }

        if ($tenant === null) {
            foreach ($files as $file) {
                if ($file->isTenantOnly()) {
                    throw ImportException::tenantFilesWithoutTenant($file->value);
                }
            }
        }
    }

    private function tenantAt(string $path): Tenant
    {
        $unitId = AdminUnitSlug::query()->where('slug_path', $path)->where('is_current', true)->value('admin_unit_id');
        $unit = $unitId === null ? null : AdminUnit::query()->find($unitId);

        if ($unit === null || $unit->level !== AdminLevel::LocalLevel) {
            throw ImportException::tenantNotFound($path);
        }

        return Tenant::query()->where('admin_unit_id', $unit->id)->first()
            ?? throw ImportException::tenantNotFound($path);
    }
}

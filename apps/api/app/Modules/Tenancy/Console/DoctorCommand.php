<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Provenance\Models\SourceType;
use App\Modules\Tenancy\Actions\CreateTenantDatabase;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Actions\MigrateTenant;
use App\Modules\Tenancy\Actions\SyncTenantReferenceData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Jobs\TenancyProbeJob;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\Support\TenantSchema;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks a deployment from the inside: configuration, both database roles,
 * Redis, the template, and every municipality's schema version.
 *
 * With --deep it also onboards a throwaway municipality, sends a job through the
 * real queue to prove the worker container picks it up in the right database,
 * then removes it again. Run it after every deploy.
 *
 * Note: the result helpers are named reportPass/reportFail/reportWarning, not
 * pass/fail — Illuminate\Console\Command already defines a public fail().
 */
final class DoctorCommand extends Command
{
    protected $signature = 'hw:doctor
        {--deep : also provision a throwaway municipality and send a job through the queue}
        {--provisioner-password= : hw_provisioner password, for --deep}
        {--queue-timeout=30 : seconds to wait for the queue worker}';

    protected $description = 'Verify this deployment: configuration, databases, Redis, municipalities';

    private int $failures = 0;

    public function handle(): int
    {
        $this->line('');
        $this->components->info('Hamro Ward deployment check');

        $this->checkConfiguration();
        $this->checkCentralDatabase();
        $this->checkSchemaOwner();
        $this->checkTemplate();
        $this->checkCacheAndQueue();
        $this->checkStorage();
        $this->checkTenants();

        if ($this->option('deep')) {
            $this->runDeepCheck();
        } else {
            $this->line('');
            $this->components->info('Add --deep to also provision a throwaway municipality and test the queue.');
        }

        $this->line('');

        if ($this->failures > 0) {
            $this->components->error("{$this->failures} check(s) failed.");

            return self::FAILURE;
        }

        $this->components->info('All checks passed.');

        return self::SUCCESS;
    }

    private function reportPass(string $label, string $detail = 'ok'): void
    {
        $this->components->twoColumnDetail($label, "<fg=green>{$detail}</>");
    }

    private function reportFail(string $label, string $detail): void
    {
        $this->failures++;
        $this->components->twoColumnDetail($label, "<fg=red>{$detail}</>");
    }

    private function reportWarning(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=yellow>{$detail}</>");
    }

    private function checkConfiguration(): void
    {
        $environment = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $key = (string) config('app.key');

        $key === ''
            ? $this->reportFail('application key', 'missing')
            : $this->reportPass('application key', 'set');

        $environment === 'production'
            ? $this->reportPass('environment', $environment)
            : $this->reportWarning('environment', $environment);

        $debug
            ? $this->reportFail('debug mode', 'ON — never in production')
            : $this->reportPass('debug mode', 'off');

        $url = (string) config('app.url');
        str_starts_with($url, 'https://')
            ? $this->reportPass('app url', $url)
            : $this->reportWarning('app url', $url.' (not https)');
    }

    private function checkCentralDatabase(): void
    {
        try {
            $connection = DB::connection((string) config('tenancy.central_connection'));
            $connection->select('select 1');

            $role = (string) $connection->selectOne('select current_user as role')->role;
            $database = (string) $connection->selectOne('select current_database() as name')->name;

            $this->reportPass('central database', "{$database} as {$role}");
        } catch (Throwable $e) {
            $this->reportFail('central database', $e->getMessage());
        }
    }

    private function checkSchemaOwner(): void
    {
        try {
            $connection = DB::connection('central_owner');
            $role = (string) $connection->selectOne('select current_user as role')->role;

            $applied = $connection->table('migrations')->count();

            $this->reportPass('schema owner', "{$role}, {$applied} migrations applied");
        } catch (Throwable $e) {
            $this->reportFail('schema owner', $e->getMessage());
        }
    }

    private function checkTemplate(): void
    {
        $template = (string) config('tenancy.template_database');

        if ($template === '') {
            $this->reportWarning('template database', 'not configured — extensions are created per database');

            return;
        }

        try {
            $row = DB::connection((string) config('tenancy.central_connection'))->selectOne(
                'select datistemplate from pg_database where datname = ?',
                [$template],
            );

            if ($row === null) {
                $this->reportFail('template database', "{$template} does not exist");

                return;
            }

            ((bool) $row->datistemplate)
                ? $this->reportPass('template database', $template)
                : $this->reportFail('template database', "{$template} is not marked as a template");
        } catch (Throwable $e) {
            $this->reportFail('template database', $e->getMessage());
        }
    }

    private function checkCacheAndQueue(): void
    {
        try {
            $token = Str::random(8);
            Cache::put('hw:doctor', $token, 60);

            Cache::get('hw:doctor') === $token
                ? $this->reportPass('cache', (string) config('cache.default'))
                : $this->reportFail('cache', 'value did not come back');
        } catch (Throwable $e) {
            $this->reportFail('cache', $e->getMessage());
        }

        $this->reportPass('queue connection', (string) config('queue.default'));

        try {
            $sourceTypes = SourceType::query()->count();

            $sourceTypes > 0
                ? $this->reportPass('reference data', "{$sourceTypes} source types")
                : $this->reportFail('reference data', 'no source types — run php artisan db:seed');
        } catch (Throwable $e) {
            $this->reportFail('reference data', $e->getMessage());
        }
    }

    private function checkStorage(): void
    {
        try {
            $path = 'diagnostics/'.Str::random(8).'.txt';
            Storage::put($path, 'ok');
            $readable = Storage::get($path) === 'ok';
            Storage::delete($path);

            $readable
                ? $this->reportPass('storage', (string) config('filesystems.default'))
                : $this->reportFail('storage', 'wrote but could not read back');
        } catch (Throwable $e) {
            $this->reportFail('storage', $e->getMessage());
        }
    }

    private function checkTenants(): void
    {
        $expected = TenantSchema::expectedVersion();

        try {
            $tenants = Tenant::query()->get();
        } catch (Throwable $e) {
            $this->reportFail('municipalities', $e->getMessage());

            return;
        }

        if ($tenants->isEmpty()) {
            $this->reportWarning('municipalities', 'none onboarded yet');

            return;
        }

        $behind = $tenants->filter(fn (Tenant $tenant): bool => $tenant->schema_version !== $expected);
        $blocked = $tenants->filter(fn (Tenant $tenant): bool => $tenant->status === TenantStatus::Maintenance);

        $behind->isEmpty()
            ? $this->reportPass('municipality schemas', "{$tenants->count()} at {$expected}")
            : $this->reportFail('municipality schemas', $behind->pluck('tenant_key')->implode(', ').' behind — run hw:tenant:migrate');

        $blocked->isEmpty()
            ? $this->reportPass('municipality status', 'none in maintenance')
            : $this->reportFail('municipality status', $blocked->pluck('tenant_key')->implode(', ').' in maintenance');
    }

    private function runDeepCheck(): void
    {
        $this->line('');
        $this->components->info('Deep check: throwaway municipality');

        $password = (string) ($this->option('provisioner-password') ?? config('database.connections.provisioner.password'));

        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Password of the hw_provisioner role');
        }

        if ($password === '') {
            $this->reportFail('provisioning', 'no provisioner password');

            return;
        }

        config(['database.connections.provisioner.password' => $password]);
        DB::purge('provisioner');

        $tenant = Tenant::query()->create([
            // No administrative unit exists for a probe, so this row is removed
            // again at the end of the check.
            'admin_unit_id' => (string) Str::uuid(),
            'status' => TenantStatus::Active,
        ]);

        try {
            app(CreateTenantDatabase::class)->handle($tenant);
            $this->reportPass('provisioning', $tenant->database_name);

            app(MigrateTenant::class)->handle($tenant);
            $this->reportPass('migrations', (string) $tenant->refresh()->schema_version);

            app(SyncTenantReferenceData::class)->handle($tenant);
            $this->reportPass('reference sync', 'version '.$tenant->refresh()->reference_version);

            $this->checkExtensions($tenant);
            $this->checkQueueRoundTrip($tenant);
        } catch (Throwable $e) {
            report($e);
            $this->reportFail('deep check', $e->getMessage());
        } finally {
            try {
                app(DropTenantDatabase::class)->force($tenant);
                $tenant->delete();
                $this->reportPass('cleanup', 'throwaway database dropped');
            } catch (Throwable $e) {
                $this->reportFail('cleanup', 'could not drop '.$tenant->database_name.': '.$e->getMessage());
            }
        }
    }

    private function checkExtensions(Tenant $tenant): void
    {
        $extensions = app(TenantManager::class)->run(
            $tenant,
            fn (): array => DB::connection('tenant')->table('pg_extension')->pluck('extname')->all(),
        );

        $missing = array_diff(['postgis', 'pg_trgm', 'btree_gist'], $extensions);

        $missing === []
            ? $this->reportPass('extensions', 'postgis, pg_trgm, btree_gist')
            : $this->reportFail('extensions', 'missing: '.implode(', ', $missing));
    }

    private function checkQueueRoundTrip(Tenant $tenant): void
    {
        $token = Str::random(12);
        $timeout = max(5, (int) $this->option('queue-timeout'));

        app(TenantManager::class)->run($tenant, function () use ($token): void {
            TenancyProbeJob::dispatch($token);
        });

        $deadline = time() + $timeout;

        while (time() < $deadline) {
            $stored = app(TenantManager::class)->run(
                $tenant,
                fn (): mixed => TenantSetting::query()->find('diagnostics.probe')?->value,
            );

            if (is_array($stored) && ($stored['token'] ?? null) === $token) {
                $this->reportPass('queue round trip', 'worker wrote into '.$tenant->database_name);

                return;
            }

            sleep(1);
        }

        $this->reportFail('queue round trip', "no result within {$timeout}s — is hamroward-queue running?");
    }
}
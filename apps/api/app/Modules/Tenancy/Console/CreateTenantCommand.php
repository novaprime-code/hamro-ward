<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Tenancy\Actions\CreateTenantDatabase;
use App\Modules\Tenancy\Actions\DropTenantDatabase;
use App\Modules\Tenancy\Actions\MigrateTenant;
use App\Modules\Tenancy\Actions\SyncTenantReferenceData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Onboards one local level: creates its database from the template, migrates it,
 * copies the reference data in, and marks it active (docs/12 §5).
 *
 * The database still has to be published separately (admin_units.is_published),
 * so onboarding never makes a half-checked municipality public by itself.
 *
 * Needs the hw_provisioner password, which is not part of the running stack's
 * environment: pass --provisioner-password, set TENANT_PROVISIONER_PASSWORD for
 * this one command, or let the prompt ask for it.
 */
final class CreateTenantCommand extends Command
{
    protected $signature = 'hw:tenant:create
        {local_level : slug path (province/district/local-level) or the unit UUID}
        {--provisioner-password= : password of the hw_provisioner role}
        {--no-interaction-password : fail instead of prompting for the password}';

    protected $description = 'Create and prepare the database for one local level';

    public function handle(
        CreateTenantDatabase $createDatabase,
        MigrateTenant $migrate,
        SyncTenantReferenceData $syncReferenceData,
        DropTenantDatabase $drop,
    ): int {
        $unit = $this->resolveLocalLevel((string) $this->argument('local_level'));

        if (! $unit instanceof AdminUnit) {
            return self::FAILURE;
        }

        if (Tenant::query()->where('admin_unit_id', $unit->id)->exists()) {
            $this->components->error("{$unit->slug} already has a tenant. Use hw:tenant:list.");

            return self::FAILURE;
        }

        if (! $this->useProvisionerPassword()) {
            return self::FAILURE;
        }

        $this->components->info("Onboarding {$unit->name_en} ({$unit->slug}) …");

        $tenant = Tenant::query()->create([
            'admin_unit_id' => $unit->id,
            'status' => TenantStatus::Provisioning,
        ]);

        try {
            $this->components->task('create database '.$tenant->database_name, function () use ($createDatabase, $tenant): void {
                $createDatabase->handle($tenant);
            });

            $this->components->task('run tenant migrations', function () use ($migrate, $tenant): void {
                $migrate->handle($tenant);
            });

            $this->components->task('copy reference data', function () use ($syncReferenceData, $tenant): void {
                $syncReferenceData->handle($tenant);
            });

            $tenant->forceFill([
                'status' => TenantStatus::Active,
                'onboarded_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            report($e);
            $this->components->error('Onboarding failed: '.$e->getMessage());
            $this->components->warn('Rolling back …');

            try {
                $drop->force($tenant);
            } catch (Throwable $dropFailure) {
                report($dropFailure);
                $this->components->warn("Could not drop {$tenant->database_name}: ".$dropFailure->getMessage());
            }

            $tenant->delete();

            return self::FAILURE;
        }

        $tenant->refresh();

        $this->newLine();
        $this->components->twoColumnDetail('Local level', (string) ($unit->name_en ?? $unit->name_ne));
        $this->components->twoColumnDetail('Tenant key', $tenant->tenant_key);
        $this->components->twoColumnDetail('Database', $tenant->database_name);
        $this->components->twoColumnDetail('Schema version', (string) $tenant->schema_version);
        $this->components->twoColumnDetail('Status', $tenant->status->value);
        $this->newLine();
        $this->components->info('Onboarded. It becomes public only once the unit and its wards are published.');

        return self::SUCCESS;
    }

    private function resolveLocalLevel(string $identifier): ?AdminUnit
    {
        $unit = AdminUnit::query()->find($identifier);

        if ($unit === null) {
            $slugPath = trim($identifier, '/');

            $unitId = AdminUnitSlug::query()
                ->where('slug_path', $slugPath)
                ->where('is_current', true)
                ->value('admin_unit_id');

            $unit = $unitId === null ? null : AdminUnit::query()->find($unitId);
        }

        if (! $unit instanceof AdminUnit) {
            $this->components->error("No administrative unit matches [{$identifier}].");
            $this->components->info('Use the slug path, for example: koshi/sunsari/namuna');

            return null;
        }

        if ($unit->level !== AdminLevel::LocalLevel) {
            $this->components->error("{$unit->slug} is a {$unit->level->value}; only a local level can be a tenant.");

            return null;
        }

        if (! $unit->isCurrent()) {
            $this->components->error("{$unit->slug} is historical and cannot be onboarded.");

            return null;
        }

        return $unit;
    }

    private function useProvisionerPassword(): bool
    {
        $password = (string) ($this->option('provisioner-password') ?? '');

        if ($password === '') {
            $password = (string) config('database.connections.provisioner.password');
        }

        if ($password === '' && ! $this->option('no-interaction-password') && $this->input->isInteractive()) {
            $password = (string) $this->secret('Password of the hw_provisioner role');
        }

        if ($password === '') {
            $this->components->error('No provisioner password. Pass --provisioner-password or set TENANT_PROVISIONER_PASSWORD for this command.');

            return false;
        }

        config(['database.connections.provisioner.password' => $password]);
        DB::purge('provisioner');

        return true;
    }
}

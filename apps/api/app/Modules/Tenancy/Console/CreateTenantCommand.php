<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Tenancy\Actions\CreateTenant;
use BackedEnum;
use Illuminate\Console\Command;
use Throwable;

/**
 * `hw:tenant:create koshi/sunsari/koshara` — onboards a local level
 * (HW-E29-F02-T01).
 *
 * Takes the slug path rather than a UUID, because the slug path is what an
 * operator can read off the public URL and check against the map. A UUID in a
 * runbook is a UUID nobody verifies.
 *
 * Creating a database is not reversible by pressing Ctrl-C, so the command
 * confirms first and prints exactly what it is about to do.
 */
final class CreateTenantCommand extends Command
{
    protected $signature = 'hw:tenant:create
                            {path : Slug path of the local level, e.g. koshi/sunsari/koshara}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Onboard a local level: create its tenant, database, schema and reference data';

    public function handle(CreateTenant $createTenant): int
    {
        $path = trim((string) $this->argument('path'), '/');
        $localLevel = $this->resolve($path);

        if ($localLevel === null) {
            $this->components->error("No local level found at \"{$path}\".");
            $this->line('  Slug paths look like province/district/local-level, and the unit must be published.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Local level', $this->label($localLevel).' ('.$path.')');
        $this->components->twoColumnDetail('Type', $this->plain($localLevel->local_level_type));
        $this->components->twoColumnDetail(
            'Wards',
            (string) AdminUnit::query()
                ->where('parent_id', $localLevel->id)
                ->where('level', AdminLevel::Ward->value)
                ->whereNull('valid_to')
                ->count(),
        );

        if (! $this->option('force') && ! $this->confirm('Create a database for this local level?', true)) {
            $this->components->warn('Nothing was created.');

            return self::SUCCESS;
        }

        try {
            $tenant = $createTenant->handle($localLevel);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());
            $this->line('  If a tenant row was created it is now in maintenance; inspect it before retrying.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Tenant {$tenant->tenant_key} is active.");
        $this->components->twoColumnDetail('Database', (string) $tenant->database_name);
        $this->components->twoColumnDetail('Schema version', (string) $tenant->schema_version);
        $this->components->twoColumnDetail('Reference version', (string) $tenant->reference_version);

        return self::SUCCESS;
    }

    /**
     * Resolves by current slug path, then falls back to the unit's own slug
     * when the path has only one segment — enough for local work, where nobody
     * types the whole chain.
     */
    private function resolve(string $path): ?AdminUnit
    {
        $slug = AdminUnitSlug::query()
            ->where('slug_path', $path)
            ->where('is_current', true)
            ->first();

        if ($slug !== null) {
            return AdminUnit::query()->find($slug->admin_unit_id);
        }

        if (! str_contains($path, '/')) {
            return AdminUnit::query()
                ->where('level', AdminLevel::LocalLevel->value)
                ->where('slug', $path)
                ->whereNull('valid_to')
                ->first();
        }

        return null;
    }

    /**
     * The central AdminUnit has no display-name helper, so the label is built
     * from the columns. A command that dies while printing a heading is a
     * command that never gets to say what went wrong.
     */
    private function label(AdminUnit $unit): string
    {
        return $unit->name_en ?: ($unit->name_ne ?: $unit->slug);
    }

    /** Prints a column whether or not the model casts it to an enum. */
    private function plain(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value) => (string) $value,
            default => '—',
        };
    }
}
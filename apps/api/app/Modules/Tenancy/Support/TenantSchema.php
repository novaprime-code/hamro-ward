<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * schema_version = name of the last tenant migration applied (docs/12 §6.1, §8).
 */
final class TenantSchema
{
    /**
     * The version the deployed code expects: the newest file in the tenant migrations path.
     */
    public static function expectedVersion(): ?string
    {
        $files = glob(base_path((string) config('tenancy.migrations_path')).'/*.php');

        if ($files === false || $files === []) {
            return null;
        }

        sort($files);

        return basename((string) end($files), '.php');
    }

    /**
     * The version actually applied to the currently initialized tenant database.
     */
    public static function appliedVersion(string $connection): ?string
    {
        if (! Schema::connection($connection)->hasTable('migrations')) {
            return null;
        }

        $version = DB::connection($connection)
            ->table('migrations')
            ->orderByDesc('id')
            ->value('migration');

        return is_string($version) ? $version : null;
    }
}

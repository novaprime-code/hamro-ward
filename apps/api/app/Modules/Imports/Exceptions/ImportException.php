<?php

declare(strict_types=1);

namespace App\Modules\Imports\Exceptions;

use App\Modules\Imports\Support\ImportFile;
use RuntimeException;

/**
 * The import cannot start at all — as opposed to a row that cannot be
 * imported, which is reported and does not stop the others being checked.
 *
 * Messages are written for the person who prepared the sheet, and say what to
 * do next.
 */
final class ImportException extends RuntimeException
{
    public static function directoryMissing(string $directory): self
    {
        return new self("No directory at \"{$directory}\". Pass the folder holding the exported CSV files.");
    }

    public static function nothingToImport(string $directory): self
    {
        return new self(
            "No import files in \"{$directory}\". Expected one or more of: "
            .implode(', ', array_map(fn (ImportFile $file): string => $file->value, ImportFile::cases())).'.',
        );
    }

    /** @param  list<string>  $names */
    public static function unknownFiles(array $names): self
    {
        return new self(
            'Unrecognised CSV file(s): '.implode(', ', $names).'. '
            .'A misspelt file name would otherwise be skipped silently and its rows never loaded — '
            .'rename it to one of the names in docs/05 §13, or move it out of the folder.',
        );
    }

    public static function tenantNotFound(string $path): self
    {
        return new self(
            "No tenant for \"{$path}\". The local level must exist and be onboarded with hw:tenant:create first; "
            .'--tenant takes its slug path, e.g. koshi/sunsari/example.',
        );
    }

    public static function tenantFilesWithoutTenant(string $file): self
    {
        return new self(
            "{$file} holds rows for one municipality, so the import needs --tenant=<province/district/local-level>.",
        );
    }

    public static function geographyWithTenant(): self
    {
        return new self(
            'admin_units.csv cannot be imported together with --tenant. Load geography on its own first, '
            .'then onboard the municipality (hw:tenant:create) or refresh its copy (hw:tenant:sync-reference), '
            .'then import its representatives. A tenant only knows the wards that existed when its reference data was synced.',
        );
    }

    public static function unreadable(string $file, string $reason): self
    {
        return new self("{$file}: {$reason}");
    }
}

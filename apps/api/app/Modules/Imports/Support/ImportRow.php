<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

/**
 * One data row of an import file, with the line it came from so every error
 * can point at the cell someone has to fix.
 */
final readonly class ImportRow
{
    /** @param  array<string, string>  $values  column => trimmed, NFC-normalised text */
    public function __construct(
        public ImportFile $file,
        public int $line,
        public array $values,
    ) {}

    /** The cell's text, or null when it is empty. A blank cell means "not known", never "". */
    public function get(string $column): ?string
    {
        $value = $this->values[$column] ?? '';

        return $value === '' ? null : $value;
    }

    /** The natural key as one string, for duplicate detection and messages. */
    public function key(): string
    {
        return implode(' · ', array_map(
            fn (string $column): string => $this->values[$column] ?? '',
            $this->file->keyColumns(),
        ));
    }
}

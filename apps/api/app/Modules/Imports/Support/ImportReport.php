<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use Illuminate\Support\Carbon;

/**
 * What an import found and did — the document the D-002 reviewer reads before
 * anything is written (docs/06 §26 DF1).
 *
 * A dry run and a real run produce the same report from the same code path;
 * the only difference is whether the transaction commits at the end. A dry run
 * that checked less than the real run would approve sheets the real run then
 * rejects halfway through.
 */
final class ImportReport
{
    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_UPDATED = 'updated';

    public const OUTCOME_UNCHANGED = 'unchanged';

    /** @var list<array{file: string, row: int, column: ?string, message: string}> */
    private array $errors = [];

    /** @var list<array{file: string, row: int, column: ?string, message: string}> */
    private array $warnings = [];

    /** @var array<string, array{created: int, updated: int, unchanged: int}> */
    private array $counts = [];

    /** @var array<string, array{rows: int, sha256: string}> */
    private array $files = [];

    /** @var list<array{subject: string, field: string, source: string, verified_by: string, reviewed_by: string, verified_on: string, outcome: string}> */
    private array $verifications = [];

    /** @var list<string> */
    private array $notes = [];

    private ?bool $committed = null;

    public function __construct(
        public readonly string $runId,
        public readonly string $directory,
        public readonly bool $dryRun,
        public readonly ?string $tenant,
        public readonly Carbon $startedAt,
    ) {}

    public function file(ImportFile $file, int $rows, string $sha256): void
    {
        $this->files[$file->value] = ['rows' => $rows, 'sha256' => $sha256];
    }

    public function error(ImportFile $file, int $row, ?string $column, string $message): void
    {
        $this->errors[] = ['file' => $file->value, 'row' => $row, 'column' => $column, 'message' => $message];
    }

    public function warning(ImportFile $file, int $row, ?string $column, string $message): void
    {
        $this->warnings[] = ['file' => $file->value, 'row' => $row, 'column' => $column, 'message' => $message];
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    /** @param  self::OUTCOME_*  $outcome */
    public function count(string $entity, string $outcome): void
    {
        $this->counts[$entity] ??= [self::OUTCOME_CREATED => 0, self::OUTCOME_UPDATED => 0, self::OUTCOME_UNCHANGED => 0];
        $this->counts[$entity][$outcome]++;
    }

    public function verification(
        string $subject,
        string $field,
        string $source,
        string $verifiedBy,
        string $reviewedBy,
        string $verifiedOn,
        string $outcome,
    ): void {
        $this->verifications[] = [
            'subject' => $subject,
            'field' => $field,
            'source' => $source,
            'verified_by' => $verifiedBy,
            'reviewed_by' => $reviewedBy,
            'verified_on' => $verifiedOn,
            'outcome' => $outcome,
        ];
    }

    public function markCommitted(bool $committed): void
    {
        $this->committed = $committed;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function committed(): bool
    {
        return $this->committed === true;
    }

    /** Created plus updated, across every entity. Zero means a re-run changed nothing. */
    public function changes(): int
    {
        return array_sum(array_map(
            fn (array $count): int => $count[self::OUTCOME_CREATED] + $count[self::OUTCOME_UPDATED],
            $this->counts,
        ));
    }

    /** @return list<array{file: string, row: int, column: ?string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<array{file: string, row: int, column: ?string, message: string}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, array{created: int, updated: int, unchanged: int}> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** @return list<array{subject: string, field: string, source: string, verified_by: string, reviewed_by: string, verified_on: string, outcome: string}> */
    public function verifications(): array
    {
        return $this->verifications;
    }

    /**
     * The run as recorded in the import.completed audit event. File hashes are
     * there so that "which sheet was this?" has an answer a year later, when
     * the file has been edited three times since.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'directory' => $this->directory,
            'tenant' => $this->tenant,
            'files' => $this->files,
            'counts' => $this->counts,
            'verifications' => $this->verifications,
            'warnings' => count($this->warnings),
        ];
    }

    /** @param  array{file: string, row: int, column: ?string, message: string}  $entry */
    public function formatLocation(array $entry): string
    {
        return $entry['file'].' row '.$entry['row'].($entry['column'] !== null ? ', '.$entry['column'] : '');
    }

    public function toMarkdown(): string
    {
        $status = match (true) {
            $this->hasErrors() => 'REFUSED — nothing was written',
            $this->dryRun => 'DRY RUN — clean, nothing was written',
            $this->committed() => 'IMPORTED',
            default => 'NOT COMMITTED',
        };

        $lines = [
            '# Import report',
            '',
            '| | |',
            '|---|---|',
            "| Status | **{$status}** |",
            "| Run | `{$this->runId}` |",
            '| Started | '.$this->startedAt->toIso8601String().' |',
            "| Directory | `{$this->directory}` |",
            '| Tenant | '.($this->tenant ?? '— (central only)').' |',
            '',
            '## Files',
            '',
            '| File | Rows | SHA-256 |',
            '|---|---:|---|',
        ];

        foreach ($this->files as $name => $file) {
            $lines[] = "| {$name} | {$file['rows']} | `{$file['sha256']}` |";
        }

        $lines = [...$lines, '', '## Changes', ''];

        if ($this->counts === []) {
            $lines[] = 'None.';
        } else {
            $lines[] = '| Entity | Created | Updated | Unchanged |';
            $lines[] = '|---|---:|---:|---:|';

            foreach ($this->counts as $entity => $count) {
                $lines[] = "| {$entity} | {$count['created']} | {$count['updated']} | {$count['unchanged']} |";
            }
        }

        $lines = [...$lines, '', '## Errors ('.count($this->errors).')', ''];

        foreach ($this->errors as $error) {
            $lines[] = '* '.$this->formatLocation($error).': '.$error['message'];
        }

        if ($this->errors === []) {
            $lines[] = 'None.';
        }

        $lines = [...$lines, '', '## Warnings ('.count($this->warnings).')', ''];

        foreach ($this->warnings as $warning) {
            $lines[] = '* '.$this->formatLocation($warning).': '.$warning['message'];
        }

        if ($this->warnings === []) {
            $lines[] = 'None.';
        }

        $lines = [...$lines, '', '## Verifications (D-002)', ''];

        if ($this->verifications === []) {
            $lines[] = 'None.';
        } else {
            $lines[] = '| Subject | Field | Source | Verified by | Reviewed by | On | Outcome |';
            $lines[] = '|---|---|---|---|---|---|---|';

            foreach ($this->verifications as $v) {
                $v = array_map(fn (string $cell): string => str_replace('|', '\\|', $cell), $v);
                $lines[] = "| {$v['subject']} | {$v['field']} | {$v['source']} | {$v['verified_by']} | {$v['reviewed_by']} | {$v['verified_on']} | {$v['outcome']} |";
            }
        }

        if ($this->notes !== []) {
            $lines = [...$lines, '', '## Notes', ''];

            foreach ($this->notes as $note) {
                $lines[] = '* '.$note;
            }
        }

        return implode("\n", $lines)."\n";
    }
}

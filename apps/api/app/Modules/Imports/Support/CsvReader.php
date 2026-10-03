<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use Normalizer;
use SplTempFileObject;

/**
 * Reads one import file into rows (docs/05 §13: UTF-8, NFC, exact header).
 *
 * Problems are reported, not thrown, so one run lists every problem in every
 * file. A file with a wrong header yields no rows at all: once the columns are
 * in doubt, nothing in that file can be trusted to mean what it says.
 *
 * Row numbers are spreadsheet row numbers — the header is row 1 — because the
 * person fixing a problem is looking at the spreadsheet, not the CSV.
 */
final class CsvReader
{
    private const BOM = "\u{FEFF}";

    /** @return list<ImportRow> */
    public function read(string $path, ImportFile $file, ImportReport $report): array
    {
        $contents = (string) file_get_contents($path);

        // Excel writes a byte-order mark at the start of "CSV UTF-8". It is
        // invisible, and left in place it becomes part of the first column's
        // name, so a correct header fails to match for no visible reason.
        if (str_starts_with($contents, self::BOM)) {
            $contents = substr($contents, strlen(self::BOM));
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $report->error($file, 1, null, 'the file is not UTF-8. Re-export it as "CSV UTF-8"; '
                .'other encodings turn Devanagari into unreadable characters.');

            return [];
        }

        /*
         * Normalise to NFC (docs/05 §13). The same Devanagari word can be
         * written as different code point sequences, and a name stored in one
         * form does not match a search, an alias or a duplicate check typed
         * in the other.
         */
        $contents = Normalizer::normalize($contents, Normalizer::FORM_C) ?: $contents;

        $csv = new SplTempFileObject;
        $csv->fwrite($contents);
        $csv->rewind();
        $csv->setFlags(SplTempFileObject::READ_CSV | SplTempFileObject::SKIP_EMPTY | SplTempFileObject::READ_AHEAD);
        $csv->setCsvControl(',', '"', '');

        $header = null;
        $rows = [];
        $number = 0;

        foreach ($csv as $record) {
            if (! is_array($record) || $record === [null]) {
                continue;
            }

            $number++;
            $cells = array_map(fn (mixed $cell): string => trim((string) $cell), $record);

            if ($header === null) {
                $header = $cells;

                if ($header !== $file->columns()) {
                    $report->error($file, 1, null, sprintf(
                        'the header row must be exactly "%s"; found "%s"',
                        implode(',', $file->columns()),
                        implode(',', $header),
                    ));

                    return [];
                }

                continue;
            }

            if (array_filter($cells, fn (string $cell): bool => $cell !== '') === []) {
                continue;
            }

            if (count($cells) !== count($header)) {
                $report->error($file, $number, null, sprintf(
                    'has %d cells where the header has %d — usually an unquoted comma inside a value',
                    count($cells),
                    count($header),
                ));

                continue;
            }

            $rows[] = new ImportRow($file, $number, array_combine($header, $cells));
        }

        if ($header === null) {
            $report->error($file, 1, null, 'the file is empty; it needs at least the header row');
        }

        return $rows;
    }
}

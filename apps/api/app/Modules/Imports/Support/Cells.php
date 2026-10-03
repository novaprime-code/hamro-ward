<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use BackedEnum;
use Illuminate\Support\Carbon;

/**
 * Reads typed values out of a row, refusing anything ambiguous with a message
 * that names the cell.
 *
 * Dates are ISO (2022-05-30) and AD only. A sheet that mixes formats cannot be
 * read safely — 03/04/2022 is a different day in Kathmandu and in a US-locale
 * spreadsheet — and a BS date in an AD column is off by 56 years and 8 months
 * without looking wrong. BS dates are kept as written, in the columns that
 * exist for that (`published_as_written`).
 */
final class Cells
{
    public static function required(ImportRow $row, string $column): string
    {
        return $row->get($column) ?? throw RowRejected::in($column, 'is required.');
    }

    public static function date(ImportRow $row, string $column, bool $required = false): ?Carbon
    {
        $value = $row->get($column);

        if ($value === null) {
            return $required ? throw RowRejected::in($column, 'is required (YYYY-MM-DD, AD).') : null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw RowRejected::in($column, "\"{$value}\" is not a date in YYYY-MM-DD (AD) form.");
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value, 'UTC');

        // AD 2034 is BS 2090; nothing in a 2022-term sheet is that late. The
        // likelier story is a BS date typed into an AD column.
        if ($date->year >= 2034) {
            throw RowRejected::in($column, "\"{$value}\" looks like a BS date. This column is AD; put the BS date as written in published_as_written where there is one.");
        }

        return $date;
    }

    /** A date or a date-time. retrieved_at is often recorded to the day, sometimes to the minute. */
    public static function dateTime(ImportRow $row, string $column, bool $required = false): ?Carbon
    {
        $value = $row->get($column);

        if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/', $value) === 1) {
            return Carbon::parse($value, 'UTC');
        }

        return self::date($row, $column, $required);
    }

    public static function bool(ImportRow $row, string $column): bool
    {
        $value = mb_strtolower((string) $row->get($column));

        return match ($value) {
            '', 'false', 'no', '0', 'n' => false,
            'true', 'yes', '1', 'y' => true,
            default => throw RowRejected::in($column, "\"{$value}\" is not true or false."),
        };
    }

    public static function int(ImportRow $row, string $column, int $default, int $min, int $max): int
    {
        $value = $row->get($column);

        if ($value === null) {
            return $default;
        }

        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            throw RowRejected::in($column, "\"{$value}\" must be a whole number from {$min} to {$max}.");
        }

        return (int) $value;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    public static function enum(ImportRow $row, string $column, string $enum, bool $required = false): ?BackedEnum
    {
        $value = $row->get($column);

        if ($value === null) {
            return $required ? throw RowRejected::in($column, 'is required.') : null;
        }

        return $enum::tryFrom($value) ?? throw RowRejected::in($column, sprintf(
            '"%s" is not one of: %s.',
            $value,
            implode(', ', array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases())),
        ));
    }

    public static function url(ImportRow $row, string $column): ?string
    {
        $value = $row->get($column);

        if ($value !== null && (preg_match('#^https?://\S+$#', $value) !== 1 || filter_var($value, FILTER_VALIDATE_URL) === false)) {
            throw RowRejected::in($column, "\"{$value}\" is not a web address starting with http:// or https://.");
        }

        return $value;
    }

    public static function email(ImportRow $row, string $column): ?string
    {
        $value = $row->get($column);

        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw RowRejected::in($column, "\"{$value}\" is not an email address.");
        }

        return $value;
    }

    public static function ref(ImportRow $row, string $column, bool $required = true): ?string
    {
        $value = $row->get($column);

        if ($value === null) {
            return $required ? throw RowRejected::in($column, 'is required.') : null;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $value) !== 1) {
            throw RowRejected::in($column, "\"{$value}\" is not a usable reference: letters, digits, dot, dash and underscore only, up to 80 characters.");
        }

        return $value;
    }

    public static function path(ImportRow $row, string $column, bool $required = true): ?string
    {
        $value = $row->get($column);

        if ($value === null) {
            return $required ? throw RowRejected::in($column, 'is required.') : null;
        }

        $value = trim($value, '/');

        if (preg_match('#^[a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*)*$#', $value) !== 1) {
            throw RowRejected::in($column, "\"{$value}\" is not a slug path such as koshi/sunsari/example/4.");
        }

        return $value;
    }

    /**
     * Latitude and longitude, both or neither, and inside Nepal.
     *
     * The bounding box is the check that matters: swapping the two columns
     * produces a valid coordinate in the Indian Ocean, and a map that puts a
     * ward office there is worse than a map with no pin.
     *
     * @return array{0: float, 1: float}|null [lat, lng]
     */
    public static function coordinates(ImportRow $row): ?array
    {
        $lat = $row->get('lat');
        $lng = $row->get('lng');

        if ($lat === null && $lng === null) {
            return null;
        }

        if ($lat === null || $lng === null) {
            throw RowRejected::in($lat === null ? 'lat' : 'lng', 'lat and lng go together: give both or neither.');
        }

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            throw RowRejected::in('lat', 'lat and lng must be decimal degrees, e.g. 26.8124 and 87.2836.');
        }

        if ((float) $lat < 26.3 || (float) $lat > 30.5 || (float) $lng < 80.0 || (float) $lng > 88.3) {
            throw RowRejected::in('lat', "{$lat}, {$lng} is outside Nepal. Check that lat and lng are not swapped.");
        }

        return [(float) $lat, (float) $lng];
    }

    /**
     * The two people who checked a fact, and when (D-002).
     *
     * They must be different people. A verification signed twice by the same
     * name is one person's opinion recorded as a review, which is the exact
     * failure the second signature exists to prevent.
     *
     * @return array{0: string, 1: string, 2: Carbon} verified by, reviewed by, verified on
     */
    public static function verifiers(ImportRow $row): array
    {
        $verifiedBy = self::required($row, 'verified_by_name');
        $reviewedBy = self::required($row, 'reviewed_by_name');

        if (ImportContext::staffId($verifiedBy) === ImportContext::staffId($reviewedBy)) {
            throw RowRejected::in('reviewed_by_name', 'must be a different person from verified_by_name (D-002): a fact is checked by one person and reviewed by another.');
        }

        $verifiedOn = self::date($row, 'verified_on', required: true);

        if ($verifiedOn->isAfter(Carbon::today('UTC'))) {
            throw RowRejected::in('verified_on', 'is in the future.');
        }

        return [$verifiedBy, $reviewedBy, $verifiedOn];
    }
}

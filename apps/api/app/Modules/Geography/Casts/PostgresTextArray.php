<?php

declare(strict_types=1);

namespace App\Modules\Geography\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads and writes a PostgreSQL text[] column.
 *
 * The PDO driver hands back the literal '{a,b,"c d"}' rather than an array, and
 * Laravel has no built-in cast for it. Sibling of PostgresUuidArray; this one
 * quotes on the way out because the values are arbitrary text.
 *
 * @implements CastsAttributes<array<int, string>, array<int, string>>
 */
final class PostgresTextArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_map(strval(...), $value));
        }

        $inner = trim((string) $value, '{}');

        if ($inner === '') {
            return [];
        }

        // {a,b,"c, d"} — split on commas outside double quotes.
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $matches, PREG_SET_ORDER);

        $values = [];

        foreach ($matches as $match) {
            $values[] = isset($match[2]) && $match[2] !== ''
                ? trim($match[2])
                : stripcslashes($match[1]);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $values = array_values(array_map(strval(...), (array) $value));

        if ($values === []) {
            return '{}';
        }

        $quoted = array_map(
            static fn (string $item): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $item).'"',
            $values,
        );

        return '{'.implode(',', $quoted).'}';
    }
}

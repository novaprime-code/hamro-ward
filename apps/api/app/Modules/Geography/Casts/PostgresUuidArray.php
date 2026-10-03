<?php

declare(strict_types=1);

namespace App\Modules\Geography\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads a PostgreSQL uuid[] column ("{a,b}") as list<string>.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PostgresUuidArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        /*
         * An already-cast value can reach get() — Builder::value() and
         * Builder::pluck() both apply casts, so code that round-trips one of
         * those back onto a model arrives here with a list, not "{a,b}".
         * Returning [] for it would turn "this unit has four ancestors" into
         * "this unit has none" without a word, and callers read that as
         * permission. Accept it instead.
         *
         * array_values re-keys it, because the declared return is a list and
         * an array arriving here is not guaranteed to be one.
         */
        if (is_array($value)) {
            $ids = [];

            foreach ($value as $id) {
                $ids[] = (string) $id;
            }

            return $ids;
        }

        if (! is_string($value) || $value === '{}' || $value === '') {
            return [];
        }

        return array_values(array_filter(
            explode(',', trim($value, '{}')),
            fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $items = is_array($value) ? $value : [];

        return '{'.implode(',', $items).'}';
    }
}

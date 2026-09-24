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
        /** @var list<string> $items */
        $items = is_array($value) ? array_values($value) : [];

        return '{'.implode(',', $items).'}';
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use RuntimeException;

/**
 * One row cannot be imported as written. Caught per row and added to the
 * report, so a sheet with twelve problems reports twelve rather than the
 * first one, and the person fixing it does not have to run the import twelve
 * times to find them all.
 */
final class RowRejected extends RuntimeException
{
    public function __construct(public readonly ?string $column, string $message)
    {
        parent::__construct($message);
    }

    public static function in(string $column, string $message): self
    {
        return new self($column, $message);
    }

    public static function row(string $message): self
    {
        return new self(null, $message);
    }
}

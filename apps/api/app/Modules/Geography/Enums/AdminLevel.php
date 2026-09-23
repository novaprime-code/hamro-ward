<?php

declare(strict_types=1);

namespace App\Modules\Geography\Enums;

/**
 * Nepal's administrative hierarchy (docs/02 §2, R1–R6).
 */
enum AdminLevel: string
{
    case Country = 'country';
    case Province = 'province';
    case District = 'district';
    case LocalLevel = 'local_level';
    case Ward = 'ward';

    public function parentLevel(): ?self
    {
        return match ($this) {
            self::Country => null,
            self::Province => self::Country,
            self::District => self::Province,
            self::LocalLevel => self::District,
            self::Ward => self::LocalLevel,
        };
    }

    public function childLevel(): ?self
    {
        return match ($this) {
            self::Country => self::Province,
            self::Province => self::District,
            self::District => self::LocalLevel,
            self::LocalLevel => self::Ward,
            self::Ward => null,
        };
    }
}

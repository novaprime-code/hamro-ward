<?php

declare(strict_types=1);

namespace App\Modules\Issues\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared shape of the issue category catalogue (docs/05 §6.1). The central
 * table is the editable one; each tenant holds a read-only replica.
 *
 * @property string $key
 * @property string $label_ne
 * @property string $label_en
 * @property string $icon
 * @property int $sort
 * @property bool $is_active
 */
abstract class BaseIssueCategory extends Model
{
    protected $table = 'issue_categories';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort');
    }
}

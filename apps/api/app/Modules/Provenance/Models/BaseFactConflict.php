<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Provenance\Enums\ConflictStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Two or more sources disagree about one fact (R16, FR-SRC-06).
 * Nothing is chosen silently: both values stay, and the UI shows the conflict
 * with authority and dates until an editor resolves it.
 *
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $field_path
 * @property ConflictStatus $status
 * @property bool $blocks_publication
 * @property string|null $resolution
 * @property mixed $resolved_value
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 */
abstract class BaseFactConflict extends Model
{
    use HasUuids;

    protected $table = 'fact_conflicts';

    /**
     * As on BaseSourceLink: the column defaults to 'open' in the database,
     * and a freshly created model did not reflect it, so isOpen() answered
     * false on a conflict that had just been recorded. A conflict that does
     * not read as open is a conflict nothing will act on.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'field_path',
        'blocks_publication',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConflictStatus::class,
            'blocks_publication' => 'boolean',
            'resolved_value' => 'json',
            'resolved_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === ConflictStatus::Open;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A document, page or record that evidence points to (docs/05 §4.2).
 *
 * @property string $id
 * @property string $source_type_key
 * @property string $title
 * @property string|null $publisher
 * @property string|null $url
 * @property string|null $document_media_id
 * @property string|null $archive_url
 * @property string|null $content_sha256
 * @property string|null $language
 * @property Carbon|null $published_at
 * @property string|null $published_as_written
 * @property Carbon $retrieved_at
 * @property string|null $notes
 * @property string|null $created_by
 */
abstract class BaseSource extends Model
{
    use HasUuids;

    protected $table = 'sources';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_type_key',
        'title',
        'publisher',
        'url',
        'document_media_id',
        'archive_url',
        'content_sha256',
        'language',
        'published_at',
        'published_as_written',
        'retrieved_at',
        'notes',
        'created_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'language' => 'ne',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'retrieved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<covariant BaseSourceType, covariant BaseSource>
     */
    abstract public function sourceType(): BelongsTo;

    /**
     * Highest authority first (rank 1 = Election Commission Nepal).
     *
     * @param  Builder<covariant BaseSource>  $query
     */
    public function scopeByAuthority(Builder $query): void
    {
        $query
            ->join('source_types', 'source_types.key', '=', 'sources.source_type_key')
            ->orderBy('source_types.authority_rank')
            ->orderByDesc('sources.published_at')
            ->select('sources.*');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * National sources: Election Commission Nepal, Government of Nepal, parties, media.
 */
final class Source extends BaseSource
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    use UsesCentralConnection;

    /**
     * @return BelongsTo<SourceType, $this>
     */
    public function sourceType(): BelongsTo
    {
        return $this->belongsTo(SourceType::class, 'source_type_key', 'key');
    }

    protected static function newFactory(): SourceFactory
    {
        return SourceFactory::new();
    }
}

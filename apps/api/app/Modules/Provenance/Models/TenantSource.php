<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Local sources: municipal notices, ward documents, local decisions.
 */
final class TenantSource extends BaseSource
{
    use UsesTenantConnection;

    /**
     * @return BelongsTo<TenantSourceType, $this>
     */
    public function sourceType(): BelongsTo
    {
        return $this->belongsTo(TenantSourceType::class, 'source_type_key', 'key');
    }
}

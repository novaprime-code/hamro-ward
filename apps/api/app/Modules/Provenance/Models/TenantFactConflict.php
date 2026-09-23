<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class TenantFactConflict extends BaseFactConflict
{
    use UsesTenantConnection;

    /**
     * @return BelongsToMany<TenantSourceLink, $this>
     */
    public function sourceLinks(): BelongsToMany
    {
        return $this->belongsToMany(
            TenantSourceLink::class,
            'fact_conflict_links',
            'fact_conflict_id',
            'source_link_id',
        );
    }
}

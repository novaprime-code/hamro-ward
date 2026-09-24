<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class FactConflict extends BaseFactConflict
{
    use UsesCentralConnection;

    /**
     * @return BelongsToMany<SourceLink, $this>
     */
    public function sourceLinks(): BelongsToMany
    {
        return $this->belongsToMany(
            SourceLink::class,
            'fact_conflict_links',
            'fact_conflict_id',
            'source_link_id',
        );
    }
}

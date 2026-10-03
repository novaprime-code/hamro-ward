<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models\Concerns;

use App\Modules\Provenance\Models\BaseSourceLink;
use App\Modules\Provenance\Models\SourceLink;
use App\Modules\Provenance\Models\TenantSourceLink;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model its evidence. Source links always live in the same database as
 * the fact they support, so the relation picks the matching model
 * (docs/12 §3, corrected 24 Sep 2026).
 */
trait HasSourceLinks
{
    /**
     * @return MorphMany<covariant BaseSourceLink, $this>
     */
    public function sourceLinks(): MorphMany
    {
        $model = $this->getConnectionName() === (string) config('tenancy.central_connection')
            ? SourceLink::class
            : TenantSourceLink::class;

        return $this->morphMany($model, 'subject', 'subject_type', 'subject_id');
    }

    /**
     * Verified evidence for the record as a whole, or for one field.
     *
     * @return MorphMany<covariant BaseSourceLink, $this>
     */
    public function verifiedSourceLinks(?string $fieldPath = null): MorphMany
    {
        // Constrain the relation's own query and return the relation, rather
        // than chaining through __call: the chain hands back whatever the last
        // forwarded call returned, which is the relation only by convention.
        $links = $this->sourceLinks();
        $query = $links->getQuery()->verified();

        $fieldPath === null
            ? $query->whereNull('field_path')
            : $query->where('field_path', $fieldPath);

        return $links;
    }
}

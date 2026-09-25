<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that two person rows were judged to be one person (docs/02 §7.2).
 *
 * Never automatic. Common Nepali name combinations repeat often enough that
 * fuzzy matching would sooner or later fuse two different representatives into
 * one and attribute one's record to the other. Two approvers, as with source
 * verification (D-002).
 */
final class PersonMerge extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return BelongsTo<Person, $this> */
    public function keptPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'kept_person_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function mergedPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'merged_person_id');
    }

    public function isFullyApproved(): bool
    {
        return $this->second_approved_by !== null;
    }
}

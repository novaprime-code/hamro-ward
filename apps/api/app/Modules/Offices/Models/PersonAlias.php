<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Geography\Enums\AliasScript;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another spelling of a person's name (docs/02 §7.2).
 *
 * Nepali names reach us in Devanagari and in several romanizations of the same
 * name, and a citizen searching for their ward chair will type whichever they
 * know. The trigram index on `normalized` is what makes that search work.
 */
final class PersonAlias extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['script' => AliasScript::class];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}

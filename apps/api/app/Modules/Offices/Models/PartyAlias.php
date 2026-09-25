<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Geography\Enums\AliasScript;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another name for a party — former names, common abbreviations, romanizations.
 * Nepali parties rename and split often enough that search has to cope with it.
 */
final class PartyAlias extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'party_aliases';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['script' => AliasScript::class];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}

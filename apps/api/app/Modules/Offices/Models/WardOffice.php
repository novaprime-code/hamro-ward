<?php

declare(strict_types=1);

namespace App\Modules\Offices\Models;

use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Provenance\Models\Concerns\HasSourceLinks;
use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a ward office is and how to reach it (docs/05 §3.2, FR-GEO-08).
 *
 * The most-used fact on a ward page, and the one most often stale on the open
 * web. Every field is shown only when a source link supports that field
 * (FR-SRC-02) — a phone number nobody can vouch for is worse than a blank,
 * because a citizen will dial it.
 *
 * @property string $id
 * @property string $ward_id
 */
final class WardOffice extends Model
{
    use HasSourceLinks;
    use HasUuids;
    use UsesTenantConnection;

    protected $guarded = [];

    /** @return BelongsTo<TenantAdminUnit, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(TenantAdminUnit::class, 'ward_id');
    }

    public function address(string $locale = 'ne'): ?string
    {
        return $locale === 'en'
            ? ($this->address_en ?? $this->address_ne)
            : ($this->address_ne ?? $this->address_en);
    }

    public function officeHours(string $locale = 'ne'): ?string
    {
        return $locale === 'en'
            ? ($this->office_hours_en ?? $this->office_hours_ne)
            : ($this->office_hours_ne ?? $this->office_hours_en);
    }
}

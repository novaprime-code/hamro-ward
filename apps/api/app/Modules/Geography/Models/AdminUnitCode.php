<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Geography\Enums\CodeScheme;
use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * External codes (CBS, ECN, MoFAGA …) — R4.
 *
 * @property string $id
 * @property string $admin_unit_id
 * @property CodeScheme $scheme
 * @property string $code
 */
final class AdminUnitCode extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'admin_unit_codes';

    /**
     * @var list<string>
     */
    protected $fillable = ['admin_unit_id', 'scheme', 'code'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scheme' => CodeScheme::class];
    }

    /**
     * @return BelongsTo<AdminUnit, $this>
     */
    public function adminUnit(): BelongsTo
    {
        return $this->belongsTo(AdminUnit::class);
    }
}

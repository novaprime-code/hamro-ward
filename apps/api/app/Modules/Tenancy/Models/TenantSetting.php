<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-tenant settings that override platform defaults, e.g.
 * issues.submissions_paused (FR-ISS-10) and issues.reporting_scope (D-012).
 *
 * @property string $key
 * @property mixed $value
 * @property string|null $reason
 * @property string|null $updated_by
 * @property Carbon $updated_at
 */
final class TenantSetting extends Model
{
    use UsesTenantConnection;

    public const CREATED_AT = null;

    protected $table = 'settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'value', 'reason', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'updated_at' => 'datetime',
        ];
    }
}

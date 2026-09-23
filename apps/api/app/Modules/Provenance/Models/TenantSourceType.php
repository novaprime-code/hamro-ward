<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;

/**
 * Read-only replica inside a tenant database, written only by
 * SyncTenantReferenceData (docs/12 §4.2).
 */
final class TenantSourceType extends BaseSourceType
{
    use UsesTenantConnection;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'authority_rank', 'label_ne', 'label_en', 'default_provenance_type', 'synced_at'];
}

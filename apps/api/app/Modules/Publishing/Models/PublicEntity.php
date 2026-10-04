<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Models;

use App\Modules\Tenancy\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One public page the sitemap and published paths should list, without
 * opening the municipality's database to find out (docs/05 §9, docs/12 §4.3).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $entity_type
 * @property string $entity_id
 * @property string $path_ne
 * @property string $path_en
 * @property string|null $title_ne
 * @property string|null $title_en
 * @property Carbon $lastmod
 * @property bool $is_published
 */
final class PublicEntity extends Model
{
    use HasUuids;
    use UsesCentralConnection;

    protected $table = 'public_entities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lastmod' => 'datetime',
            'is_published' => 'boolean',
        ];
    }
}

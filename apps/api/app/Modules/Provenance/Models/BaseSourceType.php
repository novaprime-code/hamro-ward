<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Models;

use App\Modules\Provenance\Enums\ProvenanceType;
use Illuminate\Database\Eloquent\Model;

/**
 * Source hierarchy configuration (docs/05 §4.1, project instructions §4).
 * Lower authority_rank means higher authority: 1 = Election Commission Nepal.
 *
 * @property string $key
 * @property int $authority_rank
 * @property string $label_ne
 * @property string $label_en
 * @property ProvenanceType $default_provenance_type
 */
abstract class BaseSourceType extends Model
{
    protected $table = 'source_types';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'authority_rank', 'label_ne', 'label_en', 'default_provenance_type'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authority_rank' => 'integer',
            'default_provenance_type' => ProvenanceType::class,
        ];
    }
}

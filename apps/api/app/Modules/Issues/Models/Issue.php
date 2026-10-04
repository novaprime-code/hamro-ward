<?php

declare(strict_types=1);

namespace App\Modules\Issues\Models;

use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Issues\Enums\IssueLanguage;
use App\Modules\Issues\Enums\LifecycleStatus;
use App\Modules\Issues\Enums\LocationPrecision;
use App\Modules\Issues\Enums\ModerationState;
use App\Modules\Issues\Enums\ReporterRelationship;
use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A citizen's report about a ward (docs/05 §6.2), stored in that ward's
 * municipality database.
 *
 * The location column is PostGIS geography and is not mapped here: it is
 * written and read with explicit SQL by the actions that need it, so a plain
 * toArray() can never put a reporter's exact point into a response.
 *
 * @property string $id
 * @property string $public_id
 * @property string $ward_id
 * @property string $category_key
 * @property string $title
 * @property string $description
 * @property IssueLanguage $language
 * @property LocationPrecision $location_public_precision
 * @property string|null $location_text
 * @property ModerationState $moderation_state
 * @property LifecycleStatus $lifecycle_status
 * @property int $confirmations_count
 * @property string|null $reporter_user_id
 * @property ReporterRelationship $reporter_relationship
 * @property Carbon|null $reporter_link_purge_after
 * @property string $client_fingerprint
 * @property Carbon $submitted_at
 * @property Carbon|null $published_at
 */
final class Issue extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'issues';

    protected $guarded = [];

    /**
     * Never serialised: who reported it and the abuse fingerprint are for
     * moderators and rate limits, not for any response built from toArray().
     */
    protected $hidden = ['reporter_user_id', 'reporter_relationship', 'reporter_link_purge_after', 'client_fingerprint', 'location'];

    protected function casts(): array
    {
        return [
            'language' => IssueLanguage::class,
            'location_public_precision' => LocationPrecision::class,
            'moderation_state' => ModerationState::class,
            'lifecycle_status' => LifecycleStatus::class,
            'reporter_relationship' => ReporterRelationship::class,
            'confirmations_count' => 'integer',
            'reporter_link_purge_after' => 'date',
            'submitted_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TenantAdminUnit, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(TenantAdminUnit::class, 'ward_id');
    }

    /** @return BelongsTo<TenantIssueCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantIssueCategory::class, 'category_key');
    }

    /** @return HasMany<IssueStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(IssueStatusEvent::class)->orderBy('created_at');
    }
}

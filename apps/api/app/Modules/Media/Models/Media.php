<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Tenancy\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A photograph attached to a report (HW-E12-F01; docs/06 §7).
 *
 * Lives in the municipality's own database, and its object keys live under
 * that municipality's prefix. The prefix is computed once, at upload, from the
 * tenant that was open at the time — never recomputed later from whatever
 * tenant happens to be open when a row is read, which is how a photograph
 * would end up being looked for in the wrong bucket folder.
 *
 * @property string $id
 * @property MediaStatus $status
 * @property MediaVisibility $visibility
 * @property string $mime
 * @property int $byte_size
 * @property int $width
 * @property int $height
 * @property string $storage_prefix
 * @property string|null $original_key
 * @property array<string, array<string, int|string>> $variants
 * @property string|null $uploaded_by_user_id
 * @property string|null $error
 * @property Carbon|null $processed_at
 */
final class Media extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'media';

    /**
     * Nothing is mass-assignable. Every field here is either measured by the
     * server or set by the pipeline, and none of it should ever be writable
     * from a request body.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MediaStatus::class,
            'visibility' => MediaVisibility::class,
            'variants' => 'array',
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /** `tenants/{key}/media/{id}/sm.webp` and the like. */
    public function keyFor(string $name): string
    {
        return $this->storage_prefix.'/'.$name;
    }

    /**
     * Everything this photograph owns, wherever processing got to.
     *
     * Used when cleaning up: a failed upload has an original and no variants,
     * a ready one the reverse, and a crash halfway can leave both.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = $this->original_key === null ? [] : [$this->original_key];

        foreach ($this->variants as $variant) {
            if (isset($variant['key']) && is_string($variant['key'])) {
                $keys[] = $variant['key'];
            }
        }

        return $keys;
    }

    /** Deletes every object this row refers to, on the private disk. */
    public function purgeFiles(): void
    {
        $disk = Storage::disk((string) config('media.private_disk'));

        foreach ($this->keys() as $key) {
            $disk->delete($key);
        }
    }
}

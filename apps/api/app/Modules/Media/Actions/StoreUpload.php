<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Exceptions\UploadRefused;
use App\Modules\Media\Jobs\ProcessImage;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\ImageFile;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Take one photograph in (HW-E12-F01-T01; FR-MED-01).
 *
 * The order of the checks is the point. Type comes first, from the bytes;
 * then size; then dimensions; and only once all three hold does anything get
 * written anywhere. A file that fails is never stored, so a refusal leaves
 * nothing behind to clean up and nothing for a later bug to pick up and serve.
 *
 * The row is created before the job is queued and the job is dispatched after
 * the file is written, so the worker can never find a row whose bytes have not
 * landed.
 */
final class StoreUpload
{
    public function __construct(private readonly TenantManager $tenancy) {}

    /** @throws UploadRefused */
    public function handle(UploadedFile $file, ?string $uploaderId): Media
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw UploadRefused::unreadable();
        }

        $image = ImageFile::inspect($path);

        if ($image === null) {
            throw UploadRefused::notAnAcceptedImage();
        }

        if (! ImageFile::decodable($image->mime)) {
            throw UploadRefused::cannotBeProcessed($image->mime);
        }

        $maxBytes = (int) config('media.max_bytes');

        if ($image->bytes > $maxBytes) {
            throw UploadRefused::tooLarge($maxBytes);
        }

        $maxPixels = (int) config('media.max_pixels');

        if ($image->width > $maxPixels || $image->height > $maxPixels) {
            throw UploadRefused::tooManyPixels($maxPixels);
        }

        $tenant = $this->tenancy->current();

        if ($tenant === null) {
            // Only reachable by calling this outside a tenant, which would
            // mean a photograph with nowhere to belong.
            throw UploadRefused::noMunicipality();
        }

        $id = (string) Str::uuid();
        $prefix = sprintf('tenants/%s/media/%s', $tenant->tenant_key, $id);
        $originalKey = $prefix.'/original.'.$image->extension();

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw UploadRefused::unreadable();
        }

        // The private disk, always. Nothing reaches the public one until a
        // moderator approves the report (FR-MED-03).
        Storage::disk((string) config('media.private_disk'))->put($originalKey, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $media = new Media;
        $media->forceFill([
            'id' => $id,
            'status' => MediaStatus::Uploaded->value,
            'visibility' => MediaVisibility::Private->value,
            'mime' => $image->mime,
            'byte_size' => $image->bytes,
            'width' => $image->width,
            'height' => $image->height,
            'storage_prefix' => $prefix,
            'original_key' => $originalKey,
            'variants' => [],
            'uploaded_by_user_id' => $uploaderId,
        ])->save();

        ProcessImage::dispatch($tenant->id, $media->id)->onQueue((string) config('media.queue'));

        return $media;
    }
}

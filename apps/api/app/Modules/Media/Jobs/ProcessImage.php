<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use GdImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turn an uploaded photograph into something that can be shown
 * (HW-E12-F01-T02; FR-MED-02).
 *
 * The metadata stripping is not a step in this job — it is a consequence of
 * how it works, which is the only way to be sure of it. The image is decoded
 * to pixels and new files are written from those pixels; EXIF, GPS and XMP
 * live in the container around them and are simply not carried across. There
 * is no "remove the GPS tag" call that a future format could slip past,
 * because nothing is copied in the first place.
 *
 * Then the original is deleted. It is the one file that still holds where the
 * photograph was taken, which on a report about someone's own street is a
 * home address; keeping it "just in case" would be keeping exactly the thing
 * the stripping exists to be rid of.
 *
 * Idempotent: a media already `ready` is left alone, and a re-run after a
 * partial failure overwrites the variants it had written and carries on.
 */
final class ProcessImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $mediaId,
    ) {}

    public function handle(TenantManager $tenancy): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $tenancy->run($tenant, function (): void {
            $media = Media::query()->find($this->mediaId);

            if ($media === null || $media->status === MediaStatus::Ready) {
                // Already done, or gone. Both are fine to be told twice.
                return;
            }

            try {
                $this->process($media);
            } catch (Throwable $failure) {
                $this->fail_($media, $failure);
            }
        });
    }

    private function process(Media $media): void
    {
        $disk = Storage::disk((string) config('media.private_disk'));
        $originalKey = $media->original_key;

        if ($originalKey === null || ! $disk->exists($originalKey)) {
            throw new \RuntimeException('The uploaded file is no longer in storage.');
        }

        $media->forceFill(['status' => MediaStatus::Processing->value, 'error' => null])->save();

        /*
         * Decoded from a string rather than a path: the private disk is an
         * object store in a deployment, where there is no path to open.
         */
        $source = @imagecreatefromstring((string) $disk->get($originalKey));

        if (! $source instanceof GdImage) {
            throw new \RuntimeException('The file could not be decoded as an image.');
        }

        $variants = [];

        try {
            foreach ((array) config('media.variants') as $name => $edge) {
                $variants[(string) $name] = $this->writeVariant($media, $source, (string) $name, (int) $edge);
            }
        } finally {
            imagedestroy($source);
        }

        /*
         * One save: variants recorded, original forgotten and status settled
         * together. A row that said `ready` before the delete, or kept
         * `original_key` after it, would be a row the check constraints
         * refuse anyway.
         */
        $media->forceFill([
            'variants' => $variants,
            'original_key' => null,
            'status' => MediaStatus::Ready->value,
            'error' => null,
            'processed_at' => now(),
        ])->save();

        $disk->delete($originalKey);
    }

    /**
     * One WebP at most `$edge` on its long side, never enlarged.
     *
     * @return array{key: string, width: int, height: int, bytes: int}
     */
    private function writeVariant(Media $media, GdImage $source, string $name, int $edge): array
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $edge / max($width, $height));

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $canvas instanceof GdImage) {
            throw new \RuntimeException('Could not allocate an image of that size.');
        }

        try {
            /*
             * A photograph has no transparency to keep, and flattening onto
             * white rather than black is what a PNG screenshot of a notice
             * board needs to stay readable.
             */
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white === false ? 0 : $white);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            ob_start();
            $written = imagewebp($canvas, null, (int) config('media.quality'));
            $encoded = (string) ob_get_clean();

            if ($written === false || $encoded === '') {
                throw new \RuntimeException('Could not re-encode the image.');
            }
        } finally {
            imagedestroy($canvas);
        }

        $key = $media->keyFor($name.'.webp');

        Storage::disk((string) config('media.private_disk'))->put($key, $encoded);

        return [
            'key' => $key,
            'width' => $targetWidth,
            'height' => $targetHeight,
            'bytes' => strlen($encoded),
        ];
    }

    /**
     * Record why, in words, and keep them.
     *
     * Named with a trailing underscore because `fail` is the queue's own
     * method for giving up on a job, and this is not that: the job may still
     * be retried, and what this leaves behind is a row a moderator or an
     * operator can read.
     */
    private function fail_(Media $media, Throwable $failure): void
    {
        Log::warning('A photograph could not be processed.', [
            'media_id' => $media->id,
            'tenant_id' => $this->tenantId,
            'exception' => $failure->getMessage(),
        ]);

        $media->forceFill([
            'status' => MediaStatus::Failed->value,
            'error' => $failure->getMessage(),
            'processed_at' => now(),
        ])->save();
    }
}

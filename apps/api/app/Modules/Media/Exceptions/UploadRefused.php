<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use RuntimeException;

/**
 * Why a photograph was not accepted (HW-E12-F01-T01).
 *
 * The messages are written to be shown. Someone standing in a street with one
 * hand on a phone needs to know what to do differently — take the photo
 * again, use a smaller one, use a different phone setting — not that a
 * validation rule named something failed.
 */
final class UploadRefused extends RuntimeException
{
    public static function notAnAcceptedImage(): self
    {
        return new self('That file is not a photo we can accept. Use a JPEG, PNG or WebP image.');
    }

    public static function cannotBeProcessed(string $mime): self
    {
        if ($mime === 'image/heic') {
            /*
             * iPhones shoot HEIC by default. The browser normally converts it
             * while downscaling (FR-MED-04), so this is reached by a direct
             * upload, and the fix is on the phone rather than in the form.
             */
            return new self(
                'This photo is in your phone’s HEIC format, which we cannot read yet. '
                .'In Settings → Camera → Formats, choose “Most Compatible”, or send the photo as a JPEG.',
            );
        }

        return new self('We cannot read that kind of image here. Use a JPEG, PNG or WebP photo.');
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(sprintf('That photo is larger than %d MB. Send a smaller one.', intdiv($maxBytes, 1024 * 1024)));
    }

    public static function tooManyPixels(int $maxPixels): self
    {
        return new self(sprintf('That photo is wider or taller than %d pixels. Send a smaller one.', $maxPixels));
    }

    public static function unreadable(): self
    {
        return new self('That upload did not arrive in one piece. Please try again.');
    }

    public static function noMunicipality(): self
    {
        return new self('A photo has to belong to a municipality.');
    }
}

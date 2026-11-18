<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

/**
 * What a file actually is (FR-MED-01; HW-E12-F01-T01).
 *
 * Every answer here comes from the bytes. Not from the filename, not from the
 * `Content-Type` the browser attached, not from PHP's guess based on either:
 * all three are written by whoever is uploading, and an upload endpoint that
 * believes them is an upload endpoint that stores whatever it is handed under
 * a name it chose for itself.
 *
 * `getimagesize` is used for dimensions only, and only after the type is
 * known from the magic bytes, so it is never the thing deciding what the file
 * is.
 */
final class ImageFile
{
    /**
     * Magic bytes, long enough to be a decision rather than a coincidence.
     *
     * HEIC is the odd one: the brand sits at byte 8, after the box length, so
     * it is matched separately below.
     */
    private const SIGNATURES = [
        "\xFF\xD8\xFF" => 'image/jpeg',
        "\x89PNG\r\n\x1A\n" => 'image/png',
    ];

    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1', 'heis', 'hevm'];

    public function __construct(
        public readonly string $mime,
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytes,
    ) {}

    /** The type of the file at this path, or null if it is not one we accept. */
    public static function sniff(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 32);
        fclose($handle);

        foreach (self::SIGNATURES as $signature => $mime) {
            if (str_starts_with($head, $signature)) {
                return $mime;
            }
        }

        // RIFF....WEBP — the four bytes between are the file length, so the
        // check has to skip them rather than read a single run.
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIC_BRANDS, true)) {
            return 'image/heic';
        }

        return null;
    }

    /**
     * Everything the upload needs to know, or null when the file is not an
     * image of a type we accept, or is one we cannot measure.
     */
    public static function inspect(string $path): ?self
    {
        $mime = self::sniff($path);

        if ($mime === null) {
            return null;
        }

        $bytes = @filesize($path);

        if ($bytes === false || $bytes <= 0) {
            return null;
        }

        $size = @getimagesize($path);

        // getimagesize either fails or returns both dimensions; there is no
        // third case to defend against.
        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            /*
             * A HEIC that PHP cannot measure is still a HEIC. The dimensions
             * stay unknown and the caller refuses it for a reason it can
             * explain, rather than this returning "not an image" about a file
             * that plainly is one.
             */
            return $mime === 'image/heic' ? new self($mime, 0, 0, $bytes) : null;
        }

        return new self($mime, (int) $size[0], (int) $size[1], $bytes);
    }

    /**
     * Whether this runtime can turn this type into pixels.
     *
     * GD reads JPEG, PNG and WebP and not HEIC; HEIC needs Imagick built
     * against libheif, which this image does not carry. Asking the question
     * here means an upload can be refused where the person is standing, with
     * something they can act on, instead of being accepted and failing in a
     * queue worker minutes later.
     */
    public static function decodable(string $mime): bool
    {
        $gd = function_exists('gd_info') ? gd_info() : [];

        return match ($mime) {
            'image/jpeg' => (bool) ($gd['JPEG Support'] ?? false),
            'image/png' => (bool) ($gd['PNG Support'] ?? false),
            'image/webp' => (bool) ($gd['WebP Support'] ?? false),
            'image/heic' => class_exists(\Imagick::class)
                && in_array('HEIC', array_map('strtoupper', \Imagick::queryFormats('HEI*')), true),
            default => false,
        };
    }

    /** The extension to store an original under, from its real type. */
    public function extension(): string
    {
        return match ($this->mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'heic',
        };
    }
}

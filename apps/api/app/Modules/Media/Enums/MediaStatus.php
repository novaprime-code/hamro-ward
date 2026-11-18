<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * Where a photograph is in the pipeline (docs/06 §7).
 *
 * `uploaded` means the bytes arrived and were checked, nothing more: the file
 * still carries whatever the camera wrote into it, so it is not something to
 * show anyone. Only `ready` has been through a decode and re-encode, which is
 * what removes that.
 */
enum MediaStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    /** Whether the pipeline is finished with it, either way. */
    public function settled(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }

    /** Whether it may be attached to a report being submitted. */
    public function usable(): bool
    {
        return $this === self::Ready;
    }
}

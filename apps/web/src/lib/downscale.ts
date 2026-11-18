/**
 * Shrinking a photograph before it leaves the phone (FR-MED-04; HW-E12-F01).
 *
 * Two reasons, and the second is the one that matters most.
 *
 * COST. A photograph straight from a phone camera is eight to twelve
 * megabytes. On a prepaid connection in a ward office queue, uploading three
 * of those is minutes of waiting and a visible amount of somebody's data
 * budget. Two thousand pixels on the long edge is more than any screen on
 * this platform shows, and lands around a tenth of the size.
 *
 * PRIVACY. Drawing an image onto a canvas and re-encoding it keeps the
 * pixels and nothing else: EXIF, GPS and XMP are properties of the file
 * around the pixels, and the canvas has no way to carry them. So the
 * coordinates of somebody's own street never leave their phone at all.
 *
 * The server does this again, and must (`ProcessImage`): a browser is not a
 * thing to trust about what it sends. This is the copy that spares the
 * person's data and keeps their location off the wire in the first place.
 */

/** At most this on the long edge, per FR-MED-04. */
export const MAX_EDGE = 2000;

/**
 * JPEG, not WebP. Every browser that can draw a canvas can write a JPEG,
 * while Safari only learned `toBlob` with WebP recently — and the server
 * re-encodes to WebP anyway, so choosing it here would buy nothing and lose
 * the phones most likely to be sending HEIC.
 */
export const OUTPUT_TYPE = 'image/jpeg';

export const OUTPUT_QUALITY = 0.85;

/**
 * The size to draw at: never larger than the original, never more than
 * `maxEdge` on its long side, and never zero.
 */
export function targetSize(
  width: number,
  height: number,
  maxEdge: number = MAX_EDGE,
): { width: number; height: number } {
  if (width <= 0 || height <= 0) {
    return { width: 0, height: 0 };
  }

  const scale = Math.min(1, maxEdge / Math.max(width, height));

  return {
    width: Math.max(1, Math.round(width * scale)),
    height: Math.max(1, Math.round(height * scale)),
  };
}

/** What `downscale` did, so a caller can report it honestly. */
export type Downscaled = {
  file: File;
  width: number;
  height: number;
  /** False when the browser could not read the image and the original stands. */
  reencoded: boolean;
};

/**
 * `photo.heic` becomes `photo.jpg`: the name should describe what is being
 * sent, since that is what the person will see if anything goes wrong.
 */
export function renameTo(name: string, type: string): string {
  const extension = type === 'image/jpeg' ? 'jpg' : type.split('/')[1] ?? 'bin';
  const stem = name.replace(/\.[^./\\]+$/, '');

  return `${stem === '' ? 'photo' : stem}.${extension}`;
}

/**
 * Shrink and re-encode, or hand back the original if this browser cannot
 * read the file.
 *
 * The failure path is deliberate. A browser that cannot decode HEIC — which
 * is every browser but Safari — should still be able to try the upload: the
 * server decides what it accepts, and says so in words the person can act on.
 * Refusing here would be this code guessing on the server's behalf.
 */
export async function downscale(
  file: File,
  maxEdge: number = MAX_EDGE,
): Promise<Downscaled> {
  let bitmap: ImageBitmap;

  try {
    bitmap = await createImageBitmap(file);
  } catch {
    return { file, width: 0, height: 0, reencoded: false };
  }

  const { width, height } = targetSize(bitmap.width, bitmap.height, maxEdge);

  try {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');

    if (context === null) {
      return { file, width: bitmap.width, height: bitmap.height, reencoded: false };
    }

    /*
     * A photograph has no transparency worth keeping, and a PNG screenshot
     * of a notice board flattened onto black would be unreadable — the same
     * reasoning as the server's.
     */
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, width, height);
    context.drawImage(bitmap, 0, 0, width, height);

    const blob = await new Promise<Blob | null>((resolve) => {
      canvas.toBlob(resolve, OUTPUT_TYPE, OUTPUT_QUALITY);
    });

    if (blob === null) {
      return { file, width: bitmap.width, height: bitmap.height, reencoded: false };
    }

    return {
      file: new File([blob], renameTo(file.name, OUTPUT_TYPE), {
        type: OUTPUT_TYPE,
        lastModified: Date.now(),
      }),
      width,
      height,
      reencoded: true,
    };
  } finally {
    bitmap.close();
  }
}

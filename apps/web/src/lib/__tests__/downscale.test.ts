import { describe, expect, it } from 'vitest';

import { MAX_EDGE, renameTo, targetSize } from '@/lib/downscale';

describe('targetSize', () => {
  it('fits the long edge, keeping the shape', () => {
    expect(targetSize(4000, 3000)).toEqual({ width: 2000, height: 1500 });
    expect(targetSize(3000, 4000)).toEqual({ width: 1500, height: 2000 });
    expect(targetSize(4000, 4000)).toEqual({ width: 2000, height: 2000 });
  });

  it('never enlarges a photo that is already small', () => {
    expect(targetSize(800, 600)).toEqual({ width: 800, height: 600 });
    expect(targetSize(MAX_EDGE, 10)).toEqual({ width: MAX_EDGE, height: 10 });
  });

  it('keeps a very wide panorama at least one pixel tall', () => {
    expect(targetSize(12000, 3)).toEqual({ width: 2000, height: 1 });
  });

  it('says zero for something with no size rather than dividing by it', () => {
    expect(targetSize(0, 0)).toEqual({ width: 0, height: 0 });
    expect(targetSize(-5, 100)).toEqual({ width: 0, height: 0 });
  });

  it('takes a different limit when given one', () => {
    expect(targetSize(4000, 2000, 480)).toEqual({ width: 480, height: 240 });
  });
});

describe('renameTo', () => {
  it('names the file after what is actually being sent', () => {
    expect(renameTo('IMG_4821.HEIC', 'image/jpeg')).toBe('IMG_4821.jpg');
    expect(renameTo('pothole.png', 'image/jpeg')).toBe('pothole.jpg');
    expect(renameTo('photo', 'image/jpeg')).toBe('photo.jpg');
  });

  it('copes with names that are all extension, or none', () => {
    expect(renameTo('.heic', 'image/jpeg')).toBe('photo.jpg');
    expect(renameTo('', 'image/jpeg')).toBe('photo.jpg');
  });

  it('leaves a dotted folder-ish name alone apart from its own extension', () => {
    expect(renameTo('my.photos/street.heic', 'image/jpeg')).toBe('my.photos/street.jpg');
  });
});

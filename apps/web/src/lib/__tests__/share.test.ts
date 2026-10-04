import { describe, expect, it, vi } from 'vitest';

import { shareLink } from '@/lib/share';

const DATA = { url: 'https://hamroward.example/ne/ward/koshi/sunsari/koshara/4', title: 'वडा नं. ४ · कोशारा' };

function abort(): Error {
  const error = new Error('Share canceled');
  error.name = 'AbortError';

  return error;
}

describe('shareLink', () => {
  it('uses the native share sheet where there is one, and copies nothing', async () => {
    const share = vi.fn().mockResolvedValue(undefined);
    const writeText = vi.fn();

    expect(await shareLink(DATA, { share, clipboard: { writeText } })).toBe('shared');
    expect(share).toHaveBeenCalledWith(DATA);
    expect(writeText).not.toHaveBeenCalled();
  });

  it('treats closing the sheet as a cancel, not a reason to copy', async () => {
    const writeText = vi.fn();

    expect(await shareLink(DATA, { share: vi.fn().mockRejectedValue(abort()), clipboard: { writeText } })).toBe(
      'cancelled',
    );
    expect(writeText).not.toHaveBeenCalled();
  });

  it('copies the link when there is no share sheet', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);

    expect(await shareLink(DATA, { clipboard: { writeText } })).toBe('copied');
    expect(writeText).toHaveBeenCalledWith(DATA.url);
  });

  it('copies the link when the browser says it cannot share this', async () => {
    const share = vi.fn();
    const writeText = vi.fn().mockResolvedValue(undefined);

    expect(await shareLink(DATA, { share, canShare: () => false, clipboard: { writeText } })).toBe('copied');
    expect(share).not.toHaveBeenCalled();
  });

  it('copies the link when sharing is refused for another reason', async () => {
    const refused = new Error('Not allowed');
    refused.name = 'NotAllowedError';
    const writeText = vi.fn().mockResolvedValue(undefined);

    expect(await shareLink(DATA, { share: vi.fn().mockRejectedValue(refused), clipboard: { writeText } })).toBe(
      'copied',
    );
  });

  it('reports failure when neither sharing nor copying works', async () => {
    expect(await shareLink(DATA, {})).toBe('failed');
    expect(await shareLink(DATA, { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } })).toBe(
      'failed',
    );
  });
});

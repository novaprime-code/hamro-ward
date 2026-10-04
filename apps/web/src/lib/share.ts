/**
 * Sharing a page (FR-SHR-04, docs/09 §5.2).
 *
 * The phone's own share sheet first, because that is where Viber, WhatsApp and
 * Messenger live. Where there is none — most desktop browsers, some in-app
 * browsers — the link is copied instead, and the button says so.
 *
 * Kept free of React and the DOM globals so each branch can be tested with a
 * stand-in navigator.
 */

export type ShareOutcome = 'shared' | 'copied' | 'cancelled' | 'failed';

/** The parts of `navigator` this uses; every one of them may be missing. */
export type ShareNavigator = {
  share?: (data: ShareData) => Promise<void>;
  canShare?: (data: ShareData) => boolean;
  clipboard?: { writeText: (text: string) => Promise<void> };
};

export async function shareLink(data: { url: string; title: string }, nav: ShareNavigator): Promise<ShareOutcome> {
  if (typeof nav.share === 'function' && (nav.canShare?.(data) ?? true)) {
    try {
      await nav.share(data);

      return 'shared';
    } catch (error) {
      // The reader closed the sheet. Copying behind their back would be a
      // surprise, and announcing "Link copied" after a cancel would be wrong.
      if (error instanceof Error && error.name === 'AbortError') {
        return 'cancelled';
      }
      // Anything else (no user gesture, a blocked permission, an in-app
      // browser that exposes share() and then refuses it) falls through to
      // copying, which still gets the link to the reader.
    }
  }

  if (typeof nav.clipboard?.writeText !== 'function') {
    return 'failed';
  }

  try {
    await nav.clipboard.writeText(data.url);

    return 'copied';
  } catch {
    return 'failed';
  }
}

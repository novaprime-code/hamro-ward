'use client';

import { Share2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { shareLink } from '@/lib/share';
import type { ShareOutcome } from '@/lib/share';

/**
 * The share action on a public page (FR-SHR-04; `ShareButton` in docs/09 §8).
 *
 * The link shared is the page's canonical path on the current origin, not
 * `location.href`, so a query string or fragment picked up on the way in is
 * not passed along.
 *
 * What happened is said in a live region, so a screen reader hears "Link
 * copied" as a sighted reader sees it. When neither sharing nor copying is
 * possible, the link itself is shown, selected, so it can still be copied by
 * hand.
 *
 * Labels arrive as finished strings: they cross the server–client boundary.
 */
export function ShareButton({
  path,
  title,
  labels,
}: {
  /** Locale-prefixed canonical path, e.g. `/ne/ward/koshi/sunsari/koshara/4`. */
  path: string;
  title: string;
  labels: { share: string; copied: string; failed: string };
}) {
  const [outcome, setOutcome] = useState<ShareOutcome | null>(null);
  const [url, setUrl] = useState('');
  const field = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (outcome !== 'copied') {
      return;
    }

    const timer = window.setTimeout(() => setOutcome(null), 4000);

    return () => window.clearTimeout(timer);
  }, [outcome]);

  useEffect(() => {
    if (outcome === 'failed') {
      field.current?.select();
    }
  }, [outcome]);

  async function onClick() {
    const link = new URL(path, window.location.origin).toString();

    setUrl(link);
    setOutcome(await shareLink({ url: link, title }, navigator));
  }

  return (
    <div className="flex flex-col items-end gap-1">
      <Button type="button" variant="outline" size="sm" onClick={onClick}>
        <Share2 aria-hidden="true" />
        {labels.share}
      </Button>

      <p role="status" aria-live="polite" className="text-sm text-muted-foreground">
        {outcome === 'copied' ? labels.copied : outcome === 'failed' ? labels.failed : ''}
      </p>

      {outcome === 'failed' ? (
        <input
          ref={field}
          readOnly
          value={url}
          aria-label={labels.share}
          className="w-full max-w-xs rounded-control border border-border bg-card px-2 py-1 text-sm"
          onFocus={(event) => event.currentTarget.select()}
        />
      ) : null}
    </div>
  );
}

import { OG_FONT_STACK } from '@/lib/og/fonts';
import type { OgPalette } from '@/lib/og/palette';

/**
 * The picture a ward link turns into on Facebook, Messenger, Viber and
 * WhatsApp.
 *
 * Sharing is how this platform reaches people (§18), and until now every
 * shared link arrived as a bare blue URL with no indication of which ward it
 * was for. The card exists to answer that at a glance, in the feed, before
 * anyone taps.
 *
 * What it deliberately does NOT do: no number is made to look alarming, no
 * seat is singled out, nothing is phrased as a grievance. The coverage line is
 * the only figure on the card, and it describes how complete *our* information
 * is, not how well anyone is governing. A share card is the easiest surface on
 * which to start optimising for outrage, so the rule is that it carries the
 * same sentence a ward page would (§7, §18).
 *
 * The coverage line is also what stops the card from overclaiming. A card that
 * showed only a ward number and a municipality would imply the page behind it
 * is complete; "7/9 seats confirmed" says plainly that it is not.
 *
 * Written for Satori, not for a browser: flexbox only, every container that
 * holds more than one child declares display: flex, and there are no CSS
 * custom properties to resolve — see lib/og/palette.ts.
 */

export const OG_SIZE = { width: 1200, height: 630 } as const;
export const OG_CONTENT_TYPE = 'image/png';

export function ShareCard({
  palette,
  plateLabel,
  plateValue,
  title,
  subtitle,
  coverage,
  siteName,
  footer,
}: {
  palette: OgPalette;
  /** Small word above the big value — "Ward no." — or null on the site card. */
  plateLabel: string | null;
  /** The one big thing: a ward number in the reader's own digits, or the site mark. */
  plateValue: string;
  title: string;
  subtitle: string;
  /** "7/9 seats confirmed", or null when there are no seats to describe yet. */
  coverage: string | null;
  siteName: string;
  footer: string;
}) {
  return (
    <div
      style={{
        width: '100%',
        height: '100%',
        display: 'flex',
        fontFamily: OG_FONT_STACK,
        backgroundColor: palette.surface,
        color: palette.text,
      }}
    >
      {/* The plate: the same painted-signboard idea as the ward page, which is
          what makes a shared card recognisable as this site at thumbnail size. */}
      <div
        style={{
          width: 420,
          height: '100%',
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          backgroundColor: palette.plateBg,
          padding: 40,
        }}
      >
        {plateLabel === null ? null : (
          <div style={{ fontSize: 30, color: palette.plateNumber, opacity: 0.85 }}>{plateLabel}</div>
        )}
        <div
          style={{
            fontSize: plateValue.length > 3 ? 110 : 190,
            fontWeight: 700,
            lineHeight: 1.1,
            color: palette.plateNumber,
            textAlign: 'center',
          }}
        >
          {plateValue}
        </div>
      </div>

      <div
        style={{
          flex: 1,
          height: '100%',
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'space-between',
          padding: 56,
        }}
      >
        <div style={{ display: 'flex', flexDirection: 'column' }}>
          <div
            style={{
              fontSize: 60,
              fontWeight: 700,
              lineHeight: 1.25,
              // Long Nepali municipality names are common; the card clips
              // rather than reflowing into the coverage line below it.
              maxHeight: 230,
              overflow: 'hidden',
            }}
          >
            {title}
          </div>
          <div style={{ fontSize: 30, marginTop: 16, color: palette.textMuted }}>{subtitle}</div>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column' }}>
          {coverage === null ? null : (
            <div
              style={{
                display: 'flex',
                alignSelf: 'flex-start',
                fontSize: 28,
                fontWeight: 700,
                color: palette.stateVerified,
                border: `3px solid ${palette.stateVerified}`,
                borderRadius: 8,
                padding: '10px 20px',
                marginBottom: 28,
              }}
            >
              {coverage}
            </div>
          )}

          <div
            style={{
              display: 'flex',
              alignItems: 'baseline',
              borderTop: `3px solid ${palette.line}`,
              paddingTop: 22,
            }}
          >
            <div style={{ fontSize: 30, fontWeight: 700 }}>{siteName}</div>
            <div style={{ fontSize: 24, marginLeft: 16, color: palette.textMuted }}>{footer}</div>
          </div>
        </div>
      </div>
    </div>
  );
}

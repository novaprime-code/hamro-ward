import { readFile } from 'node:fs/promises';
import { join } from 'node:path';

/**
 * Fonts for the share cards.
 *
 * `next/font` self-hosts the site's own fonts so the browser never requests
 * Google at runtime. The share card is rendered by Satori, which is a separate
 * renderer with no access to that pipeline: it needs the raw font bytes, and
 * without them every Devanagari glyph renders as an empty box. A share card
 * full of tofu is worse than no card at all, and it fails silently — nobody
 * sees it except the people the link was shared with.
 *
 * So the bytes are vendored. These are the Fontsource subsets of Noto Sans
 * Devanagari (SIL Open Font License 1.1, copy in the same directory): the
 * Devanagari and Latin subsets at two weights, about 166 KB in total. Satori
 * reads ttf, otf and woff — not woff2, which is why the larger woff files are
 * the ones here.
 *
 * They live under public/ because that is a directory the production
 * Dockerfile already copies into the runtime image. An assets directory
 * elsewhere would be dropped from the standalone output and the failure would
 * only appear on a shared link in production.
 *
 * process.cwd() is the app root in both environments: Next's standalone
 * server.js chdirs to its own directory before starting.
 */

const DIRECTORY = join(process.cwd(), 'public', 'og', 'fonts');

export type OgFont = {
  name: string;
  data: ArrayBuffer;
  weight: 400 | 700;
  style: 'normal';
};

/**
 * Satori keys a registered font by family name, weight and style, so two files
 * sharing all three do not merge — the second is simply not reachable. The
 * Devanagari and Latin subsets are therefore separate families, combined in a
 * CSS font stack by the card (OG_FONT_STACK).
 *
 * Registering both as one family is the obvious thing to do and produces a
 * card that looks correct until you notice that every comma, hyphen and slash
 * is an empty box: the Devanagari subset has no ASCII punctuation, and the
 * Latin subset that does was shadowed. "7/9 seats confirmed" loses its slash.
 */
export const OG_FONT_STACK = 'Noto, NotoLatin';

async function load(file: string): Promise<ArrayBuffer> {
  const buffer = await readFile(join(DIRECTORY, file));

  return buffer.buffer.slice(
    buffer.byteOffset,
    buffer.byteOffset + buffer.byteLength,
  ) as ArrayBuffer;
}

/**
 * Read once per process, not once per card. Resolved at module scope so the
 * second share of the same ward does not touch the disk again.
 */
const fonts: Promise<OgFont[]> = Promise.all([
  load('noto-devanagari-400.woff'),
  load('noto-devanagari-700.woff'),
  load('noto-latin-400.woff'),
  load('noto-latin-700.woff'),
]).then(([devanagari400, devanagari700, latin400, latin700]): OgFont[] => [
  { name: 'Noto', data: devanagari400, weight: 400, style: 'normal' },
  { name: 'Noto', data: devanagari700, weight: 700, style: 'normal' },
  { name: 'NotoLatin', data: latin400, weight: 400, style: 'normal' },
  { name: 'NotoLatin', data: latin700, weight: 700, style: 'normal' },
]);

export function ogFonts(): Promise<OgFont[]> {
  return fonts;
}

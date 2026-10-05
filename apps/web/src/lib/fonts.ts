import localFont from 'next/font/local';

/*
 * The site's two typefaces, vendored rather than fetched from Google at build
 * time.
 *
 * `next/font/google` downloads the files during `next build`. The browser
 * never asks Google, but the build does, and in CI that request failed
 * intermittently (a TypeError inside next/font's Google loader, twice in an
 * hour on unrelated changes). A civic site's deploy should not depend on a
 * third party answering at the moment the image is built.
 *
 * These are the same files Google serves: the Fontsource variable-weight
 * subsets of Anek Devanagari and Noto Sans Devanagari (SIL Open Font License
 * 1.1, copies in src/fonts/). Devanagari and Latin are separate files, so each
 * is its own face with its unicode-range, and the CSS stacks the pair
 * (--font-display, --font-sans in globals.css): the browser downloads a subset
 * only when the page uses a character from it.
 *
 * Anek's width axis is not included; nothing requested it from Google either.
 */

/* next/font requires literal arguments, so the unicode ranges are written out in each call. */

export const anekDevanagari = localFont({
  src: '../fonts/anek-devanagari-devanagari-wght-normal.woff2',
  weight: '100 900',
  variable: '--font-anek-deva',
  display: 'swap',
  declarations: [
    {
      prop: 'unicode-range',
      value:
        'U+0900-097F, U+1CD0-1CF9, U+200C-200D, U+20A8, U+20B9, U+20F0, U+25CC, U+A830-A839, U+A8E0-A8FF, U+11B00-11B09',
    },
  ],
});

export const anekLatin = localFont({
  src: '../fonts/anek-devanagari-latin-wght-normal.woff2',
  weight: '100 900',
  variable: '--font-anek-latin',
  display: 'swap',
  declarations: [
    {
      prop: 'unicode-range',
      value:
        'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
    },
  ],
});

export const notoDevanagari = localFont({
  src: '../fonts/noto-sans-devanagari-devanagari-wght-normal.woff2',
  weight: '100 900',
  variable: '--font-noto-deva',
  display: 'swap',
  declarations: [
    {
      prop: 'unicode-range',
      value:
        'U+0900-097F, U+1CD0-1CF9, U+200C-200D, U+20A8, U+20B9, U+20F0, U+25CC, U+A830-A839, U+A8E0-A8FF, U+11B00-11B09',
    },
  ],
});

export const notoLatin = localFont({
  src: '../fonts/noto-sans-devanagari-latin-wght-normal.woff2',
  weight: '100 900',
  variable: '--font-noto-latin',
  display: 'swap',
  declarations: [
    {
      prop: 'unicode-range',
      value:
        'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
    },
  ],
});

/** Every font variable, for the root <html> of each layout. */
export const fontVariables = [
  anekDevanagari,
  anekLatin,
  notoDevanagari,
  notoLatin,
]
  .map((font) => font.variable)
  .join(' ');

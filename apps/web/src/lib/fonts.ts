import { Anek_Devanagari, Noto_Sans_Devanagari } from 'next/font/google';

/*
 * Shared by the public and staff root layouts, so both load the same files.
 */
/* Self-hosted through next/font: no request to Google at runtime, and the
   Devanagari subset only. Anek has a width axis, used by the ward plate. */
export const anek = Anek_Devanagari({
  subsets: ['devanagari', 'latin'],
  weight: ['600', '700', '800'],
  variable: '--font-anek',
  display: 'swap',
});

export const noto = Noto_Sans_Devanagari({
  subsets: ['devanagari', 'latin'],
  weight: ['400', '500', '600'],
  variable: '--font-noto',
  display: 'swap',
});

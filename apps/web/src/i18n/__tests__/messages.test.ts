import { describe, expect, it } from 'vitest';

import en from '../../../messages/en.json';
import ne from '../../../messages/ne.json';

/**
 * The two catalogues must carry the same keys.
 *
 * `getMessages` merges the default catalogue underneath the requested one, so a
 * key present only in Nepali does not crash an English page — it silently
 * renders Nepali. A key present in neither renders as the key itself: a reader
 * gets `evidence.field.party_id` where a heading should be.
 *
 * Both failures are invisible in review, because the page still renders and the
 * build still passes. They are only visible to whoever is reading the site in
 * the language nobody on the team is checking — which, for this project, is the
 * language most readers use.
 */

const neKeys = Object.keys(ne as Record<string, string>).sort();
const enKeys = Object.keys(en as Record<string, string>).sort();

describe('message catalogues', () => {
  it('has no key missing from English', () => {
    expect(neKeys.filter((key) => !enKeys.includes(key))).toEqual([]);
  });

  it('has no key missing from Nepali', () => {
    expect(enKeys.filter((key) => !neKeys.includes(key))).toEqual([]);
  });

  it('has no empty strings', () => {
    const blank = [...Object.entries(ne), ...Object.entries(en)]
      .filter(([, value]) => typeof value !== 'string' || value.trim() === '')
      .map(([key]) => key);

    expect(blank).toEqual([]);
  });

  it('keeps the same placeholders in both languages', () => {
    /*
     * A translation that drops {place} renders a sentence with a hole in it; one
     * that invents {name} renders the literal braces. Both look like a typo to a
     * reader and like nothing at all to a build.
     */
    const placeholders = (value: string): string[] =>
      (value.match(/\{[a-zA-Z]+\}/g) ?? []).sort();

    const mismatched = neKeys.filter((key) => {
      const a = placeholders((ne as Record<string, string>)[key]);
      const b = placeholders((en as Record<string, string>)[key] ?? '');

      return a.join(',') !== b.join(',');
    });

    expect(mismatched).toEqual([]);
  });
});

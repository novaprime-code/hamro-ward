/**
 * Where this deployment lives, read at request time (D-015).
 *
 * This value differs between staging and production, which is exactly why it
 * must not be a NEXT_PUBLIC_* variable: those are compiled into the browser
 * bundle, and an image that carries one environment's hostname can never be
 * promoted to another. Everything that needs an absolute URL — canonical
 * links, Open Graph tags, share cards — is rendered on the server, so reading
 * the environment here costs nothing and keeps one image good everywhere.
 *
 * Set HW_SITE_URL per stack. An unset or malformed value falls back to
 * localhost rather than throwing: bad metadata on one page is a smaller
 * failure than a site that will not boot.
 */
const FALLBACK = 'http://localhost:3000';

export function siteUrl(): URL {
  const configured = process.env.HW_SITE_URL?.trim();

  if (!configured) {
    return new URL(FALLBACK);
  }

  try {
    return new URL(configured);
  } catch {
    // Deliberately not fatal — see above.
    console.warn(`[hamroward] HW_SITE_URL is not a valid URL: ${configured}`);

    return new URL(FALLBACK);
  }
}

/**
 * The staff dashboard's host. Separate from the public site by design: staff
 * and citizen sessions must never share a cookie host (docs/12 §11).
 */
export function adminHost(): string | null {
  return process.env.HW_ADMIN_HOST?.trim() || null;
}

import { revalidateTag } from 'next/cache';

import {
  SIGNATURE_HEADER,
  TIMESTAMP_HEADER,
  parseTags,
  usableSecret,
  verify,
} from '@/lib/revalidate';

/**
 * POST /api/revalidate — Laravel marks cached pages stale (HW-E08-F01-T04).
 *
 * Reached only over the internal network in the stacks (REVALIDATE_URL points
 * at the web container), and signed regardless: the network boundary is the
 * first check, not the only one (docs/06 §11 TB8).
 *
 * The answers say as little as they can. 503 when this deployment has no usable
 * secret — an operator problem, not a caller's; 401 for any signature failure,
 * without saying which; 400 for a correctly signed body with tags this site
 * never issues.
 */
export const dynamic = 'force-dynamic';
export const runtime = 'nodejs';

export async function POST(request: Request): Promise<Response> {
  const secret = usableSecret(process.env.REVALIDATE_SECRET);

  if (secret === null) {
    return Response.json({ error: 'revalidation is not configured' }, { status: 503 });
  }

  const body = await request.text();
  const verdict = verify(
    request.headers.get(TIMESTAMP_HEADER),
    request.headers.get(SIGNATURE_HEADER),
    body,
    secret,
  );

  if (verdict !== 'ok') {
    return Response.json({ error: 'unauthorised' }, { status: 401 });
  }

  const tags = parseTags(body);

  if (tags === null) {
    return Response.json({ error: 'expected {"tags": [...]} of known tags' }, { status: 400 });
  }

  for (const tag of tags) {
    revalidateTag(tag);
  }

  return Response.json({ revalidated: tags });
}

/**
 * A fixed-window request counter, in memory.
 *
 * Deliberately small. It is not a distributed rate limiter and does not try to
 * be: the counters live in one process, they are lost on every deploy, and a
 * second web container would keep its own. What it does buy is the thing the
 * site has none of today — a ceiling on how fast one address can pull pages
 * out of a server that renders each one and calls the API behind it.
 *
 * The durable limit belongs in front of this, at the reverse proxy, where it
 * survives restarts and sees every container. This is the layer that ships in
 * the application, so it ships now.
 *
 * Kept as a pure function of (key, now) so it can be tested without a clock,
 * a request or a server.
 */

export type RateLimitDecision =
  | { allowed: true; remaining: number }
  | { allowed: false; retryAfterSeconds: number };

export type RateLimiter = {
  check: (key: string, now?: number) => RateLimitDecision;
  /** Visible for tests. */
  size: () => number;
};

export function createRateLimiter({
  limit,
  windowMs,
  maxKeys = 10_000,
}: {
  limit: number;
  windowMs: number;
  /**
   * A ceiling on tracked addresses, so that traffic from many addresses — which
   * is what a botnet or a large NAT looks like — cannot grow this map until the
   * process runs out of memory. Reaching it drops the expired entries first and,
   * failing that, clears everything: forgetting who has been counted is a far
   * smaller problem than falling over.
   */
  maxKeys?: number;
}): RateLimiter {
  const windows = new Map<string, { count: number; resetAt: number }>();

  function prune(now: number): void {
    for (const [key, window] of windows) {
      if (window.resetAt <= now) {
        windows.delete(key);
      }
    }

    if (windows.size >= maxKeys) {
      windows.clear();
    }
  }

  return {
    check(key, now = Date.now()) {
      const window = windows.get(key);

      if (window === undefined || window.resetAt <= now) {
        if (windows.size >= maxKeys) {
          prune(now);
        }

        windows.set(key, { count: 1, resetAt: now + windowMs });

        return { allowed: true, remaining: limit - 1 };
      }

      if (window.count >= limit) {
        return {
          allowed: false,
          retryAfterSeconds: Math.max(1, Math.ceil((window.resetAt - now) / 1000)),
        };
      }

      window.count += 1;

      return { allowed: true, remaining: limit - window.count };
    },
    size: () => windows.size,
  };
}

/**
 * The address to count against, taken from the LAST entry of X-Forwarded-For.
 *
 * This is the part that is easy to get backwards. The reverse proxy in front of
 * this app appends the address it received the connection from, so the header
 * reads `client, …, peer-as-seen-by-the-proxy`. Everything before the last
 * entry was supplied by the caller and can say anything at all — a limiter
 * keyed on the first entry is defeated by one forged header, which is the
 * failure mode of most hand-rolled rate limits.
 *
 * The last entry is what the proxy observed, so it is the one that costs
 * something to change.
 *
 * Returns null when there is no header, which means the request did not come
 * through the proxy — local development, or a health check on the container's
 * own port. Those are not counted rather than being counted together under one
 * bucket, which would let a single container-local caller lock out the real
 * one.
 */
export function clientAddress(forwardedFor: string | null): string | null {
  if (forwardedFor === null) {
    return null;
  }

  const entries = forwardedFor
    .split(',')
    .map((entry) => entry.trim())
    .filter((entry) => entry !== '');

  return entries.at(-1) ?? null;
}

/**
 * The sliver of Cloudflare's widget API this codebase touches
 * (components/account/register-form.tsx). The script is loaded only where a
 * site key is configured, so every use has to tolerate its absence.
 */
declare global {
  interface Window {
    turnstile?: { reset: (widget?: string) => void };
  }
}

export {};

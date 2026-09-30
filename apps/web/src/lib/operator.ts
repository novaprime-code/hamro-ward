/**
 * Who runs Hamro Ward, read at request time.
 *
 * The operator is a real company with a real registration (D-003), and the
 * About page names it. None of that is decided yet — governance conditions
 * G1–G3 are open — so every value here is optional and the pages render an
 * honest "not published yet" state when one is missing.
 *
 * That is deliberate. A placeholder company name on a civic platform is worse
 * than an empty field: a visitor cannot tell an invented operator from a real
 * one, and the whole argument of this site is that it never presents an
 * unverified thing as a fact (§3, FR-SRC-02).
 *
 * Read at request time rather than compiled in, for the same reason as
 * HW_SITE_URL: one image has to be promotable between environments (D-015),
 * and the operator's details land in the stack's .env the day they are agreed,
 * with a restart and no rebuild.
 */

export type Operator = {
  /** Registered company name, exactly as registered. */
  name: string | null;
  /** Company registration number in Nepal (G1). */
  registration: string | null;
  /** Where a citizen reports an error in our data (Milestone A, A7). */
  correctionsEmail: string | null;
};

function env(name: string): string | null {
  return process.env[name]?.trim() || null;
}

export function operator(): Operator {
  return {
    name: env('HW_OPERATOR_NAME'),
    registration: env('HW_OPERATOR_REGISTRATION'),
    correctionsEmail: env('HW_CORRECTIONS_EMAIL'),
  };
}

/**
 * True only when the operator can be named publicly — both the name and the
 * registration it can be checked against. A name with no registration number
 * is an assertion nobody can verify, which is exactly what this platform tells
 * its users not to accept.
 */
export function isOperatorPublishable(value: Operator): boolean {
  return value.name !== null && value.registration !== null;
}

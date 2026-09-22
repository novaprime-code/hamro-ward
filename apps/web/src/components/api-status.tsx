/**
 * Development aid: shows whether the site reached the API. Removed once real
 * ward data renders (HW-E08-F01-T02). Status is never colour-only (docs/09 §2).
 */
export function ApiStatus({
  status,
  okLabel,
  downLabel,
}: {
  status: 'ok' | 'down';
  okLabel: string;
  downLabel: string;
}) {
  const healthy = status === 'ok';

  return (
    <p
      className="mt-8 inline-flex items-center gap-2 rounded-[6px] border px-3 py-2 text-[15px]"
      style={{
        borderColor: healthy ? 'var(--color-state-verified)' : 'var(--color-danger)',
        color: healthy ? 'var(--color-state-verified)' : 'var(--color-danger)',
      }}
      role="status"
    >
      <span aria-hidden="true">{healthy ? '✓' : '!'}</span>
      {healthy ? okLabel : downLabel}
    </p>
  );
}

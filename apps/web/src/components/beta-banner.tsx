export function BetaBanner({ message }: { message: string }) {
  return (
    <p className="border-b border-rule bg-[color-mix(in_srgb,var(--color-brass)_12%,white)] px-4 py-2 text-center text-[15px] text-brass-ink">
      {message}
    </p>
  );
}

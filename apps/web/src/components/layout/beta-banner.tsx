export function BetaBanner({ message }: { message: string }) {
  return (
    <p className="border-b border-line bg-accent-wash px-4 py-2 text-center text-sm text-accent-ink">
      {message}
    </p>
  );
}

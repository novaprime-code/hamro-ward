/**
 * The staff dashboard's front door. Sign-in, the two-factor challenge and the
 * moderation queue arrive in HW-E13-F02-T02 and HW-E14-F02; until then this
 * page proves the host routing and its headers end to end.
 */
export default function StaffHome() {
  return (
    <div className="space-y-3">
      <h1 className="font-display text-[28px] font-bold">हाम्रो वडा · Staff</h1>
      <p className="text-muted-foreground">
        Staff sign-in is not open yet. Moderators, verifiers and data editors will sign in here, with two-step
        verification.
      </p>
    </div>
  );
}

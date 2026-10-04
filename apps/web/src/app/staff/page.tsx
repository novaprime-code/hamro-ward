import { StaffSignIn } from '@/components/staff/staff-sign-in';

/**
 * The staff dashboard's front door (HW-E13-F02-T02). Sign-in, the two-step
 * challenge and first-time enrolment all happen here, against Fortify through
 * the same-origin rewrites; the moderation queue follows in HW-E14-F02.
 */
export default function StaffHome() {
  return (
    <div className="space-y-6 py-6">
      <p className="text-center font-display text-[20px] font-bold">हाम्रो वडा · Staff</p>
      <StaffSignIn />
    </div>
  );
}

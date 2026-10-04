<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Controllers;

use App\Modules\Staff\Models\StaffMembership;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/staff/me — who is signed in on the admin host, and where they
 * may act. Reached only past AuthenticateForHost, so only with confirmed
 * two-factor and inside the session limits.
 */
final class StaffMeController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var StaffUser $staff */
        $staff = $request->user();

        return response()->json(['data' => [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'operator_admin' => $staff->isOperatorAdmin(),
            'memberships' => $staff->memberships()->live()->with('tenant')->get()
                ->map(fn (StaffMembership $membership): array => [
                    'tenant_key' => $membership->tenant->tenant_key,
                    'role' => $membership->role->value,
                ])->values()->all(),
        ]])->header('Cache-Control', 'private, no-store');
    }
}

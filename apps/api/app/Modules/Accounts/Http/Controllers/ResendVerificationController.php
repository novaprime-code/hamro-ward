<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Modules\Accounts\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /email/verification-notification — send the link again
 * (HW-E30-F01-T02).
 *
 * Works signed in or signed out, because sign-up does not sign anyone in and
 * "it never arrived" is the commonest thing to go wrong in this whole flow.
 * Signed out it takes the address in the body.
 *
 * The answer is always the same 202, whatever the address turns out to be:
 * this endpoint would otherwise undo the enumeration-safety that sign-up is
 * built around. Throttling is on the route, keyed by address, so it cannot be
 * turned into a way of mailing someone repeatedly.
 */
final class ResendVerificationController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            $validated = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
            $user = User::query()->where('email', mb_strtolower(trim($validated['email'])))->first();
        }

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'status' => 'verification_sent',
            'message' => 'If that address needs confirming, a link is on its way.',
        ], 202)->header('Cache-Control', 'no-store');
    }
}

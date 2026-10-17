<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Modules\Accounts\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me — the signed-in citizen (docs/12 §11.3).
 *
 * Fetched by the browser after hydration, never rendered on the server, so
 * public HTML never varies by user and stays cacheable (§11.4).
 */
final class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        // On the admin host the session guard is staff's; a staff account
        // has no citizen profile, and this route is not its business.
        abort_unless($user instanceof User, 404);

        /*
         * `can_report` rather than leaving the screens to work it out from
         * the flags: the rule is "active and verified" today and gains
         * clauses later (a kill switch, a reporting scope), and every one of
         * those would otherwise have to be re-implemented in the header.
         *
         * `saved_wards` is the count, not the list. The header needs to know
         * whether to say "My wards" or "Add your ward"; the list itself is a
         * request the My wards screen makes when someone opens it.
         */
        return response()->json(['data' => [
            'display_name' => $user->display_name,
            'preferred_locale' => $user->preferred_locale,
            'email_verified' => $user->hasVerifiedEmail(),
            'can_report' => $user->canReport(),
            'saved_wards' => $user->wards()->count(),
        ]])->header('Cache-Control', 'private, no-store');
    }
}

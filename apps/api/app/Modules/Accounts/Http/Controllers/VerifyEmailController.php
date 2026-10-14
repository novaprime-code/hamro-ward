<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Modules\Accounts\Models\User;
use App\Modules\Accounts\Support\PublicUrl;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /email/verify/{id}/{hash} — confirm an address (HW-E30-F01-T02).
 *
 * Fortify's version of this route requires a signed-in session, which does
 * not fit the flow this platform has: sign-up deliberately does not sign
 * anyone in (see RegisterController), and the link is often opened on a
 * different device from the one that filled the form — the form on a laptop,
 * the mail on a phone.
 *
 * It is safe without a session because the link proves two things by itself:
 * a signature this application made and has not expired, and a hash of the
 * address being confirmed. Someone who holds the link holds the mailbox,
 * which is the only thing verification is trying to establish.
 *
 * It answers with a redirect rather than JSON because it is clicked in a mail
 * client, not called by the screens.
 */
final class VerifyEmailController
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        $valid = $user instanceof User
            && hash_equals(sha1((string) $user->getEmailForVerification()), $hash);

        if (! $valid) {
            // Same answer as an expired link: nothing here says whether the
            // id existed.
            return $this->land($request, 'expired', null);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $this->land($request, 'verified', $user->preferred_locale);
    }

    private function land(Request $request, string $outcome, ?string $locale): RedirectResponse
    {
        $locale ??= (string) config('accounts.fallback_locale');

        return redirect()->away(PublicUrl::to($locale, 'account/verify', [$outcome => '1']));
    }
}

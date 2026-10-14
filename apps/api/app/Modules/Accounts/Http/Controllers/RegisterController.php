<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Modules\Accounts\Actions\Fortify\PasswordRules;
use App\Modules\Accounts\Models\User;
use App\Modules\Accounts\Notifications\RegistrationAttempted;
use App\Modules\Accounts\Support\Turnstile;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /register — citizen sign-up (HW-E30-F01-T02; docs/09 §5.6, 12 §11.3).
 *
 * Not Fortify's registration route, and the difference is the point. Fortify
 * creates the account, signs the person in and returns 201. This endpoint
 * must do neither of the last two, for one reason:
 *
 * AN ANSWER THAT DIFFERS BY WHETHER THE ADDRESS IS KNOWN hands anyone a way
 * to ask "does nabin@example.com have an account on Hamro Ward?" — and on a
 * platform where accounts exist to report on local government, that question
 * has to stay unanswerable. So a taken address gets the same 201 and the same
 * words as a free one; what differs is only which email is sent, and that is
 * visible to the owner of the mailbox alone.
 *
 * That is also why sign-up does not sign anyone in. A session cookie on the
 * response would answer the question just as plainly as a 422 would, however
 * carefully the body were worded. The person reads their email, follows the
 * link, and signs in — one extra step, bought with the thing the extra step
 * is protecting.
 *
 * Email verification is where the account becomes useful: reporting needs it
 * (User::canReport), browsing never did.
 */
final class RegisterController
{
    public function __invoke(Request $request, Turnstile $turnstile): JsonResponse
    {
        $input = $request->all();

        $validator = Validator::make($input, [
            'display_name' => ['required', 'string', 'min:2', 'max:60'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => PasswordRules::for(null),
            'preferred_locale' => ['required', 'in:ne,en'],
        ]);

        /*
         * The bot check reads as a validation error on its own field, so the
         * screen can put the message under the widget it belongs to. It runs
         * after the cheap rules so a mistyped form does not spend a token.
         */
        $validator->after(function ($validator) use ($request, $turnstile): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $turnstile->verify($request->string('turnstile_token')->toString(), $request->ip())) {
                $validator->errors()->add('turnstile_token', 'That check did not pass. Please try again.');
            }
        });

        $validated = $validator->validate();

        $email = mb_strtolower(trim($validated['email']));
        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            // Deliberately not a 422. The owner of the mailbox learns that
            // someone tried; nobody else learns anything at all.
            $existing->notify(new RegistrationAttempted);
        } else {
            $user = new User;
            $user->forceFill([
                'display_name' => trim($validated['display_name']),
                'email' => $email,
                'password' => $validated['password'],
                'preferred_locale' => $validated['preferred_locale'],
            ])->save();

            // Sends the verification mail (User implements MustVerifyEmail).
            event(new Registered($user));
        }

        return response()->json([
            'status' => 'verification_sent',
            'message' => 'Check your email for a link to confirm the address.',
        ], 201)->header('Cache-Control', 'no-store');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Notifications;

use App\Modules\Accounts\Support\PublicUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Reset your password" (HW-E30-F01-T02).
 *
 * Laravel's own builds its link from `route('password.reset')`, which this
 * application does not have: Fortify's views are off, so there is no GET
 * route to name, and the mail threw rather than sent. The screen lives in the
 * web tier, so the link has to be built there instead — no signature needed,
 * the token in the query is the secret.
 */
final class ResetPasswordLink extends ResetPassword
{
    use Localised;

    public function toMail($notifiable): MailMessage
    {
        $locale = $this->localeFor($notifiable);
        $minutes = (int) config('auth.passwords.'.config('fortify.passwords').'.expire', 60);

        $url = PublicUrl::to($locale, 'account/reset-password', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject($this->say([
                'ne' => 'हाम्रो वडा — पासवर्ड बनाउनुहोस्',
                'en' => 'Hamro Ward — reset your password',
            ], $locale))
            ->line($this->say([
                'ne' => 'तपाईंको खाताको पासवर्ड बनाउन अनुरोध आएको छ।',
                'en' => 'We received a request to reset your account password.',
            ], $locale))
            ->action($this->say([
                'ne' => 'नयाँ पासवर्ड राख्नुहोस्',
                'en' => 'Choose a new password',
            ], $locale), $url)
            ->line($this->say([
                'ne' => 'यो लिंक {0} मिनेटपछि काम गर्दैन। तपाईंले अनुरोध गर्नुभएको होइन भने केही गर्नु पर्दैन — पासवर्ड उस्तै रहन्छ।',
                'en' => 'The link stops working after {0} minutes. If you did not ask for this, there is nothing to do: your password stays as it is.',
            ], $locale, (string) $minutes));
    }
}

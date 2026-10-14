<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Notifications;

use App\Modules\Accounts\Support\PublicUrl;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/**
 * "Confirm your email address" (HW-E30-F01-T02).
 *
 * Replaces Laravel's own so the link points at the web tier rather than at
 * this container (Support\PublicUrl), and so the mail is in the language the
 * person chose when they signed up.
 */
final class VerifyEmailAddress extends VerifyEmail
{
    use Localised;

    public function toMail($notifiable): MailMessage
    {
        $locale = $this->localeFor($notifiable);
        $url = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject($this->say([
                'ne' => 'हाम्रो वडा — आफ्नो इमेल पुष्टि गर्नुहोस्',
                'en' => 'Hamro Ward — confirm your email address',
            ], $locale))
            ->greeting($this->say([
                'ne' => 'नमस्ते {0},',
                'en' => 'Hello {0},',
            ], $locale, $notifiable->display_name))
            ->line($this->say([
                'ne' => 'हाम्रो वडामा खाता बनाउन यो ठेगाना प्रयोग गरिएको छ। तलको बटन थिचेर पुष्टि गर्नुहोस्।',
                'en' => 'This address was used to create an account on Hamro Ward. Confirm it with the button below.',
            ], $locale))
            ->action($this->say([
                'ne' => 'इमेल पुष्टि गर्नुहोस्',
                'en' => 'Confirm my email',
            ], $locale), $url)
            ->line($this->say([
                'ne' => 'यो लिंक {0} घण्टापछि काम गर्दैन। तपाईंले खाता बनाउनुभएको होइन भने केही गर्नु पर्दैन — यो सन्देश बेवास्ता गर्नुहोस्।',
                'en' => 'The link stops working after {0} hours. If you did not create an account, there is nothing to do: ignore this message.',
            ], $locale, (string) config('accounts.verify_hours')));
    }

    /** The signed link, built on the public origin so the signature checks out. */
    protected function verificationUrl($notifiable): string
    {
        return PublicUrl::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addHours((int) config('accounts.verify_hours')),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1((string) $notifiable->getEmailForVerification()),
            ],
        );
    }
}

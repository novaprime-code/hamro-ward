<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Notifications;

use App\Modules\Accounts\Support\PublicUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Someone tried to sign up with your address" (HW-E30-F01-T02).
 *
 * The other half of an enumeration-safe sign-up: the form answers everyone
 * the same way, so the one person entitled to know that the address is
 * already taken — whoever reads the mailbox — is told here instead.
 *
 * It names no account detail and offers no link that acts: just what
 * happened, and the way back in if the person had simply forgotten.
 */
final class RegistrationAttempted extends Notification
{
    use Localised;
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->localeFor($notifiable);

        return (new MailMessage)
            ->subject($this->say([
                'ne' => 'हाम्रो वडा — यो ठेगानामा खाता पहिले नै छ',
                'en' => 'Hamro Ward — this address already has an account',
            ], $locale))
            ->line($this->say([
                'ne' => 'कसैले यो ठेगाना प्रयोग गरी हाम्रो वडामा नयाँ खाता बनाउन खोज्यो। तपाईंको खाता पहिले नै भएकाले नयाँ बनेन, र केही पनि बदलिएको छैन।',
                'en' => 'Someone tried to create a new Hamro Ward account with this address. You already have one, so nothing was created and nothing has changed.',
            ], $locale))
            ->action($this->say([
                'ne' => 'साइन इन गर्नुहोस्',
                'en' => 'Sign in',
            ], $locale), PublicUrl::to($locale, 'account/sign-in'))
            ->line($this->say([
                'ne' => 'यो तपाईं नै हुनुहुन्थ्यो भने, पासवर्ड बिर्सनुभएको हुन सक्छ — साइन इन पृष्ठबाट नयाँ राख्न सकिन्छ।',
                'en' => 'If that was you, you may have forgotten your password: the sign-in page can send you a link to set a new one.',
            ], $locale));
    }
}

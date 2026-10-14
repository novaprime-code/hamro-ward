<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Notifications;

use App\Modules\Accounts\Models\User;

/**
 * Mail in the language the account chose (docs/09 §3).
 *
 * Nepali is the default here as it is everywhere else: someone who never
 * touched the language switch gets Nepali, not English. The strings are held
 * as pairs in each notification rather than in lang files because there are a
 * handful of them and keeping the two languages side by side is what stops
 * one of them from quietly going stale.
 */
trait Localised
{
    protected function localeFor(object $notifiable): string
    {
        $locale = $notifiable instanceof User ? $notifiable->preferred_locale : null;

        return in_array($locale, ['ne', 'en'], true) ? $locale : (string) config('accounts.fallback_locale');
    }

    /**
     * Picks one of a `['ne' => …, 'en' => …]` pair.
     *
     * @param  array{ne: string, en: string}  $pair
     */
    protected function say(array $pair, string $locale, string ...$values): string
    {
        $text = $pair[$locale] ?? $pair['ne'];

        foreach ($values as $index => $value) {
            $text = str_replace('{'.$index.'}', $value, $text);
        }

        return $text;
    }
}

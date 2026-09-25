<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Support\PersonSlug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictional people. Names are built from invented syllables rather than a real
 * name list, so a test fixture can never be mistaken for a real representative
 * if it leaks into a screenshot or a seeded environment.
 *
 * @extends Factory<Person>
 */
final class PersonFactory extends Factory
{
    protected $model = Person::class;

    private const GIVEN = ['Aran', 'Bikhu', 'Chetan', 'Dipal', 'Ekraj', 'Fulmaya', 'Girija', 'Hemanti'];

    private const FAMILY = ['Baraili', 'Chhetri', 'Dhungel', 'Emwal', 'Ghimire', 'Humagain'];

    private const GIVEN_NE = ['अरण', 'बिखु', 'चेतन', 'दिपल', 'एकराज', 'फुलमाया', 'गिरिजा', 'हेमन्ती'];

    private const FAMILY_NE = ['बराइली', 'क्षेत्री', 'ढुंगेल', 'एम्वाल', 'घिमिरे', 'हुमागाईं'];

    public function definition(): array
    {
        $index = $this->faker->numberBetween(0, count(self::GIVEN) - 1);
        $familyIndex = $this->faker->numberBetween(0, count(self::FAMILY) - 1);

        $nameEn = self::GIVEN[$index].' '.self::FAMILY[$familyIndex];
        $nameNe = self::GIVEN_NE[$index].' '.self::FAMILY_NE[$familyIndex];

        return [
            'slug' => PersonSlug::for($nameEn),
            'full_name_ne' => $nameNe,
            'full_name_en' => $nameEn,
            'is_published' => false,
            'published_at' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    /** A person with only a Devanagari name — the slug falls back (docs/02 §7.2). */
    public function devanagariOnly(): self
    {
        return $this->state(fn (): array => [
            'full_name_en' => null,
            'slug' => PersonSlug::for(null),
        ]);
    }

    public function mergedInto(Person $kept): self
    {
        return $this->state(fn (): array => [
            'merged_into_person_id' => $kept->id,
            'is_published' => false,
            'published_at' => null,
        ]);
    }
}

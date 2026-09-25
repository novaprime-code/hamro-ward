<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Offices\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fictional parties. Never a real party name, in a factory or a fixture: a
 * seeded environment that leaks would otherwise put invented statements next to
 * a real party's name.
 *
 * @extends Factory<Party>
 */
final class PartyFactory extends Factory
{
    protected $model = Party::class;

    private const NAMES = [
        ['उदाहरण दल', 'Example Party', 'EP'],
        ['नमूना पार्टी', 'Sample Party', 'SP'],
        ['परीक्षण मोर्चा', 'Test Front', 'TF'],
        ['काल्पनिक गठबन्धन', 'Fictional Alliance', 'FA'],
    ];

    public function definition(): array
    {
        [$nameNe, $nameEn, $abbreviation] = self::NAMES[$this->faker->numberBetween(0, count(self::NAMES) - 1)];

        return [
            'slug' => Str::slug($nameEn).'-'.Str::lower(Str::random(4)),
            'name_ne' => $nameNe,
            'name_en' => $nameEn,
            'abbreviation_ne' => $abbreviation,
            'abbreviation_en' => $abbreviation,
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

    /** A party that no longer exists. Historical holdings still point at it. */
    public function dissolved(): self
    {
        return $this->state(fn (): array => [
            'valid_from' => '2013-01-01',
            'valid_to' => '2022-01-01',
        ]);
    }
}

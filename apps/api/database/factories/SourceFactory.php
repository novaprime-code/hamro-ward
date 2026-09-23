<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Models\Source;
use App\Modules\Provenance\Models\SourceType;
use Database\Seeders\SourceTypeSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictional national sources.
 *
 * @extends Factory<Source>
 */
final class SourceFactory extends Factory
{
    protected $model = Source::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        self::ensureSourceTypes();

        return [
            'source_type_key' => SourceTypeKey::Ecn->value,
            'title' => 'Local election results '.fake()->numberBetween(2074, 2079),
            'publisher' => 'Election Commission Nepal',
            'url' => 'https://example.test/'.fake()->uuid(),
            'language' => 'ne',
            'published_at' => fake()->date(),
            'retrieved_at' => now(),
        ];
    }

    public function ofType(SourceTypeKey $key): self
    {
        return $this->state(['source_type_key' => $key->value]);
    }

    public function document(): self
    {
        return $this->state([
            'url' => null,
            'document_media_id' => fake()->uuid(),
        ]);
    }

    public static function ensureSourceTypes(): void
    {
        if (SourceType::query()->count() === 0) {
            (new SourceTypeSeeder)->run();
        }
    }
}

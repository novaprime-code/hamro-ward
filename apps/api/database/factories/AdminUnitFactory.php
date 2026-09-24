<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Geography\Models\AdminUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fictional administrative units. Each level creates its missing ancestors,
 * so AdminUnit::factory()->ward()->create() builds a full chain.
 * The single country row is reused.
 *
 * @extends Factory<AdminUnit>
 */
final class AdminUnitFactory extends Factory
{
    protected $model = AdminUnit::class;

    /**
     * Defaults to a province under the (shared) country.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Key order matters: closures see the attributes resolved before them,
        // so ward_number must precede slug and name_en.
        return [
            'level' => AdminLevel::Province,
            'parent_id' => fn (): string => self::country()->id,
            'local_level_type' => null,
            'ward_number' => null,
            'slug' => fn (): string => $this->uniqueSlug('pradesh'),
            'name_en' => fn (array $attributes): string => Str::headline($attributes['slug']),
            'name_ne' => null,
        ];
    }

    public function province(): self
    {
        return $this->state([
            'level' => AdminLevel::Province,
            'parent_id' => fn (): string => self::country()->id,
            'slug' => fn (): string => $this->uniqueSlug('pradesh'),
        ]);
    }

    public function district(): self
    {
        return $this->state([
            'level' => AdminLevel::District,
            'parent_id' => fn (): string => AdminUnit::factory()->province()->create()->id,
            'slug' => fn (): string => $this->uniqueSlug('jilla'),
        ]);
    }

    public function localLevel(LocalLevelType $type = LocalLevelType::Municipality): self
    {
        return $this->state([
            'level' => AdminLevel::LocalLevel,
            'parent_id' => fn (): string => AdminUnit::factory()->district()->create()->id,
            'local_level_type' => $type,
            'slug' => fn (): string => $this->uniqueSlug('namuna'),
        ]);
    }

    public function ward(?int $number = null): self
    {
        return $this->state([
            'level' => AdminLevel::Ward,
            'parent_id' => fn (): string => AdminUnit::factory()->localLevel()->create()->id,
            'ward_number' => fn (array $attributes): int => $number ?? $this->nextWardNumber($attributes['parent_id']),
            'slug' => fn (array $attributes): string => (string) $attributes['ward_number'],
            'name_en' => fn (array $attributes): string => 'Ward '.$attributes['ward_number'],
            'name_ne' => null,
        ]);
    }

    public function childOf(AdminUnit $parent): self
    {
        return $this->state(['parent_id' => $parent->id]);
    }

    public function closed(string $validTo = '2017-03-10'): self
    {
        return $this->state(['valid_to' => $validTo]);
    }

    /**
     * Sets is_published directly — for tests. Application code uses PublishAdminUnit.
     */
    public function published(): self
    {
        return $this->afterCreating(function (AdminUnit $unit): void {
            $unit->forceFill(['is_published' => true, 'published_at' => now()])->save();
        });
    }

    public static function country(): AdminUnit
    {
        $existing = AdminUnit::query()
            ->where('level', AdminLevel::Country->value)
            ->whereNull('valid_to')
            ->first();

        return $existing ?? AdminUnit::query()->create([
            'level' => AdminLevel::Country,
            'parent_id' => null,
            'slug' => 'nepal',
            'name_ne' => 'नेपाल',
            'name_en' => 'Nepal',
        ]);
    }

    private function uniqueSlug(string $base): string
    {
        return $base.'-'.Str::lower(Str::random(6));
    }

    private function nextWardNumber(string $parentId): int
    {
        $max = AdminUnit::query()
            ->where('parent_id', $parentId)
            ->where('level', AdminLevel::Ward->value)
            ->max('ward_number');

        return ((int) $max) + 1;
    }
}

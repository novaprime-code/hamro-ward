<?php

declare(strict_types=1);

namespace App\Modules\Demo\Actions;

use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Demo\Data\DemoLocalLevel;
use App\Modules\Geography\Actions\PublishAdminUnit;
use App\Modules\Geography\Actions\RefreshSlugPaths;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use Illuminate\Support\Facades\DB;

/**
 * Builds the demonstration's administrative hierarchy in the central database
 * (docs/13 §1–2).
 *
 * Real country, real provinces, real districts — invented local levels and
 * their wards. The real part is real because the hierarchy is what the product
 * is organised around and inventing it would teach a citizen something false
 * about their own country.
 *
 * Idempotent: identifiers are deterministic (UUIDv5 over the dataset
 * namespace), so a second run updates the same rows rather than creating a
 * parallel Nepal.
 *
 * Publication goes through PublishAdminUnit rather than setting the column,
 * because the rule that a unit cannot be published under an unpublished
 * ancestor is exactly the rule a seeder would otherwise quietly break — and
 * then the tenant replica would carry wards that can never appear.
 *
 * ---------------------------------------------------------------------------
 * Two things here are deliberate and easy to "tidy" back into bugs:
 *
 *  1. Rows are written with forceFill, not create()/firstOrCreate(). Mass
 *     assignment silently DROPS any attribute missing from the model's
 *     $fillable, so a seeder written that way can create a hierarchy with no
 *     names in it and report success. A seeder is not user input; there is
 *     nothing here to protect against.
 *
 *  2. Enums are passed as ->value (plain strings). That is correct whether or
 *     not the model casts the column to an enum: Eloquent accepts the backing
 *     value for an enum cast, and stores the string when there is no cast.
 * ---------------------------------------------------------------------------
 */
final class SeedDemoGeography
{
    public function __construct(
        private readonly PublishAdminUnit $publish,
        private readonly RefreshSlugPaths $refreshSlugPaths,
    ) {}

    /** @return array{provinces: int, districts: int, local_levels: int, wards: int} */
    public function handle(): array
    {
        $counts = ['provinces' => 0, 'districts' => 0, 'local_levels' => 0, 'wards' => 0];

        DB::connection('central')->transaction(function () use (&$counts): void {
            $country = $this->country();

            foreach (DemoDataset::localLevels() as $demo) {
                $province = $this->province($demo, $country);
                $district = $this->district($demo, $province);
                $localLevel = $this->localLevel($demo, $district);

                $counts['provinces']++;
                $counts['districts']++;
                $counts['local_levels']++;
                $counts['wards'] += $this->wards($demo, $localLevel);
            }

            $this->refreshSlugPaths->handle($country);
        });

        return $counts;
    }

    private function country(): AdminUnit
    {
        /*
         * An existing country is reused whatever its id: a real import may have
         * created Nepal before the demonstration ran, and a second country row
         * would break the hierarchy trigger for everything underneath.
         */
        $existing = AdminUnit::query()
            ->where('level', AdminLevel::Country->value)
            ->whereNull('valid_to')
            ->first();

        $country = $existing ?? $this->upsert(DemoDataset::id('admin_unit', 'nepal'), [
            'level' => AdminLevel::Country->value,
            'parent_id' => null,
            'slug' => 'nepal',
            'name_ne' => 'नेपाल',
            'name_en' => 'Nepal',
        ]);

        return $this->publishIfNeeded($country);
    }

    /**
     * Provinces and districts are shared: two demonstration local levels in the
     * same province must not produce two provinces. The deterministic id makes
     * the second write an update of the first, which is also what makes the
     * whole action re-runnable.
     */
    private function province(DemoLocalLevel $demo, AdminUnit $country): AdminUnit
    {
        $province = $this->upsert(DemoDataset::id('admin_unit', $demo->provinceSlug), [
            'level' => AdminLevel::Province->value,
            'parent_id' => $country->id,
            'slug' => $demo->provinceSlug,
            'name_ne' => $demo->provinceNameNe,
            'name_en' => $demo->provinceNameEn,
        ]);

        return $this->publishIfNeeded($province);
    }

    private function district(DemoLocalLevel $demo, AdminUnit $province): AdminUnit
    {
        $district = $this->upsert(DemoDataset::id('admin_unit', $demo->provinceSlug, $demo->districtSlug), [
            'level' => AdminLevel::District->value,
            'parent_id' => $province->id,
            'slug' => $demo->districtSlug,
            'name_ne' => $demo->districtNameNe,
            'name_en' => $demo->districtNameEn,
        ]);

        return $this->publishIfNeeded($district);
    }

    private function localLevel(DemoLocalLevel $demo, AdminUnit $district): AdminUnit
    {
        $localLevel = $this->upsert(DemoDataset::id('admin_unit', $demo->key), [
            'level' => AdminLevel::LocalLevel->value,
            'parent_id' => $district->id,
            'local_level_type' => $demo->type->value,
            'slug' => $demo->key,
            'name_ne' => $demo->nameNe,
            'name_en' => $demo->nameEn,
        ]);

        return $this->publishIfNeeded($localLevel);
    }

    private function wards(DemoLocalLevel $demo, AdminUnit $localLevel): int
    {
        $created = 0;

        for ($number = 1; $number <= $demo->wards; $number++) {
            $ward = $this->upsert(DemoDataset::id('admin_unit', $demo->key, 'ward', (string) $number), [
                'level' => AdminLevel::Ward->value,
                'parent_id' => $localLevel->id,
                'ward_number' => $number,
                'slug' => (string) $number,
                'name_ne' => 'वडा नं. '.$this->devanagariNumber($number),
                'name_en' => 'Ward '.$number,
            ]);

            $this->publishIfNeeded($ward);
            $created++;
        }

        return $created;
    }

    /**
     * Insert or update by deterministic id, bypassing mass-assignment
     * protection. is_published is never written here — publication is
     * PublishAdminUnit's job, and re-running must not silently republish
     * something an operator unpublished.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(string $id, array $attributes): AdminUnit
    {
        $unit = AdminUnit::query()->find($id) ?? new AdminUnit;

        $unit->forceFill(['id' => $id, ...$attributes])->save();

        return $unit;
    }

    /**
     * PublishAdminUnit exposes publish() and unpublish(), not handle() — the
     * class has two directions, so a single handle() would not say which.
     */
    private function publishIfNeeded(AdminUnit $unit): AdminUnit
    {
        if ($unit->is_published) {
            return $unit;
        }

        $this->publish->publish($unit);

        return $unit->refresh();
    }

    /** Ward names read in Devanagari numerals, as a Nepali citizen expects (§16). */
    private function devanagariNumber(int $number): string
    {
        return strtr((string) $number, [
            '0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४',
            '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९',
        ]);
    }
}

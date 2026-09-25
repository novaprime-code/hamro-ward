<?php

declare(strict_types=1);

namespace App\Modules\Demo\Actions;

use App\Modules\Demo\Data\DemoDataset;
use App\Modules\Demo\Data\DemoLocalLevel;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * The invented people and parties every demonstration tenant refers to
 * (docs/13 §3).
 *
 * Central, like the real thing: office holdings live in each tenant and point
 * at these rows across the database boundary (D-014). Seeding them here rather
 * than per tenant also means the demonstration exercises that boundary instead
 * of quietly avoiding it.
 *
 * Names are chosen by index, never at random. Given names come from a pool
 * that deliberately excludes those of well-known politicians, and surnames
 * come from the pool the local level's own region makes plausible — so a ward
 * in Baitadi reads differently from a ward in Rautahat, as it should.
 */
final class SeedDemoDirectory
{
    /** Roughly a fifth of holders are independents, spread deterministically. */
    private const INDEPENDENT_EVERY = 5;

    /** @return array{people: int, parties: int} */
    public function handle(): array
    {
        $people = 0;

        DB::connection('central')->transaction(function () use (&$people): void {
            $this->parties();

            foreach (DemoDataset::localLevels() as $demo) {
                $people += $this->peopleFor($demo);
            }
        });

        return ['people' => $people, 'parties' => count(DemoDataset::parties())];
    }

    private function parties(): void
    {
        foreach (DemoDataset::parties() as $party) {
            Party::query()->updateOrCreate(
                ['id' => DemoDataset::id('party', $party['key'])],
                [
                    'slug' => $party['key'],
                    'name_ne' => $party['ne'],
                    'name_en' => $party['en'],
                    'abbreviation_ne' => $party['abbr'],
                    'abbreviation_en' => $party['abbr'],
                    'is_published' => true,
                    'published_at' => now(),
                ],
            );
        }
    }

    /**
     * Enough people to fill every seat the local level has, plus a margin, so
     * the seat seeder never runs out mid-ward and leave a half-filled
     * committee that looks like a bug.
     */
    private function peopleFor(DemoLocalLevel $demo): int
    {
        $needed = $demo->seededWards() * 5 + 4;
        $given = DemoDataset::givenNames();
        $romanisations = DemoDataset::surnameRomanisations();

        for ($index = 0; $index < $needed; $index++) {
            [$givenNe, $givenEn] = $given[$index % count($given)];
            $surnameNe = $demo->surnames[intdiv($index, count($given)) % count($demo->surnames)];
            $surnameEn = $romanisations[$surnameNe] ?? 'Baraili';

            Person::query()->updateOrCreate(
                ['id' => DemoDataset::id('person', $demo->key, (string) $index)],
                [
                    // The slug suffix is the local level and index rather than a
                    // random string: deterministic URLs mean a screenshot of a
                    // person page stays valid after a re-seed.
                    'slug' => strtolower($givenEn.'-'.$surnameEn.'-'.$demo->key.$index),
                    'full_name_ne' => $givenNe.' '.$surnameNe,
                    'full_name_en' => $givenEn.' '.$surnameEn,
                    'is_published' => true,
                    'published_at' => now(),
                ],
            );
        }

        return $needed;
    }

    /** The person seated at a given index of a local level. */
    public static function personId(DemoLocalLevel $demo, int $index): string
    {
        return DemoDataset::id('person', $demo->key, (string) $index);
    }

    /**
     * Which party a seat's holder belongs to, or null for an independent.
     * Deterministic, and spread so that no party holds a suspicious majority —
     * a demonstration is not the place to imply an election result.
     */
    public static function partyIdFor(int $seatOrdinal): ?string
    {
        if ($seatOrdinal % self::INDEPENDENT_EVERY === 0) {
            return null;
        }

        $parties = DemoDataset::parties();

        return DemoDataset::id('party', $parties[$seatOrdinal % count($parties)]['key']);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Geography\Queries;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Support\Digits;
use App\Modules\Geography\Support\NameNormalizer;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * "Where do I live?", answered from whatever a person actually types
 * (HW-E09-F01, FR-GEO-07).
 *
 * The picker on the home page filters a list the browser already has, which is
 * right for choosing between four municipalities and wrong for the question
 * people actually arrive with. Nobody thinks "I will select my municipality and
 * then my ward". They think "Itahari 4", or "इटहरी ४", or "itahari-4", and they
 * type that.
 *
 * So a trailing number is treated as a ward number, in either script, and the
 * rest as a place name. "कोशारा ४" lands on ward 4 of Koshara. "koshara" lands
 * on the municipality. Both are one query away from the page they wanted rather
 * than two.
 *
 * ---------------------------------------------------------------------------
 * Why this scans rather than using a search engine
 *
 * D-005 P5 replaced Meilisearch with PostgreSQL for v0/v1, and at Nepal's
 * actual size that is not a compromise. The country has 7 provinces, 77
 * districts, 753 local levels and roughly 6,743 wards — the entire searchable
 * universe is under eight thousand rows and will not grow, because it is the
 * administrative structure of a country rather than a content table. A filter
 * across it costs less than the round trip that delivered the query.
 *
 * The trigram index on admin_unit_aliases still earns its place: it is what
 * catches a misspelling or an older name, which is the case a LIKE cannot help
 * with (R5).
 * ---------------------------------------------------------------------------
 */
final class SearchPlaces
{
    /** Below this, a trigram match is noise rather than a near-miss. */
    private const SIMILARITY_FLOOR = 0.3;

    private const MAX_RESULTS = 20;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(string $query): Collection
    {
        [$namePart, $wardNumber] = $this->split($query);

        if ($namePart === '' && $wardNumber === null) {
            return collect();
        }

        $openLocalLevelIds = Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->pluck('admin_unit_id');

        if ($openLocalLevelIds->isEmpty()) {
            return collect();
        }

        $localLevels = $this->matchingLocalLevels($namePart, $openLocalLevelIds->all());

        if ($localLevels->isEmpty()) {
            return collect();
        }

        $results = $localLevels
            ->map(fn (array $match): array => [
                'type' => 'local_level',
                'score' => $match['score'],
                'unit' => $match['unit'],
                'ward_number' => null,
            ])
            ->all();

        /*
         * A number turns every matching municipality into a ward result as
         * well, not instead. Someone typing "4" after a half-remembered name
         * may have the name slightly wrong, and dropping the municipality row
         * would leave them with nothing to correct towards.
         */
        if ($wardNumber !== null) {
            foreach ($this->wardsOf($localLevels, $wardNumber) as $ward) {
                $results[] = [
                    'type' => 'ward',
                    // Above its own municipality: they asked for the ward.
                    'score' => $ward['score'] + 5,
                    'unit' => $ward['unit'],
                    'ward_number' => $wardNumber,
                ];
            }
        }

        return collect($results)
            ->sortByDesc('score')
            ->take(self::MAX_RESULTS)
            ->values();
    }

    /**
     * Splits "कोशारा ४" into a name and a ward number.
     *
     * Only a TRAILING number counts. A leading or middle one is part of the
     * name — "ward 4 office" is not a search for ward 4 of a place called
     * "office" — and several local levels have digits in their names.
     *
     * @return array{0: string, 1: int|null}
     */
    private function split(string $query): array
    {
        $latin = Digits::toLatin(trim($query));

        if (preg_match('/^(.*?)[\s,\-–/]*(\d{1,2})$/u', $latin, $matches) === 1) {
            $number = (int) $matches[2];

            // Ward numbers run 1–99 (the admin_units CHECK). A trailing 0 or a
            // year is not a ward, so the whole string stays a name.
            if ($number >= 1 && $number <= 99 && trim($matches[1]) !== '') {
                return [NameNormalizer::normalize($matches[1]), $number];
            }
        }

        return [NameNormalizer::normalize($latin), null];
    }

    /**
     * @param  list<string>  $openLocalLevelIds
     * @return Collection<int, array{unit: AdminUnit, score: int}>
     */
    private function matchingLocalLevels(string $needle, array $openLocalLevelIds): Collection
    {
        $units = AdminUnit::query()
            ->publiclyVisible()
            ->where('level', AdminLevel::LocalLevel->value)
            ->whereIn('id', $openLocalLevelIds)
            ->with(['parent.parent'])
            ->get();

        // An empty name with a ward number ("4" alone) is not a search — it
        // would return every municipality's ward 4 in the country.
        if ($needle === '') {
            return collect();
        }

        $aliasMatches = $this->aliasMatches($needle, $openLocalLevelIds);

        return $units
            ->map(function (AdminUnit $unit) use ($needle, $aliasMatches): ?array {
                $score = $this->score($unit, $needle, $aliasMatches);

                return $score === 0 ? null : ['unit' => $unit, 'score' => $score];
            })
            ->filter()
            ->values();
    }

    /**
     * Scores one municipality against the needle.
     *
     * The ladder is deliberate: an exact name beats a prefix, a prefix beats a
     * substring, and a real name beats an alias — because an alias is by
     * definition the name something used to have, or a way people get it wrong,
     * and neither should outrank what the place is actually called today (R5).
     *
     * @param  array<string, float>  $aliasMatches  admin_unit_id => similarity
     */
    private function score(AdminUnit $unit, string $needle, array $aliasMatches): int
    {
        $names = array_filter([
            NameNormalizer::normalize((string) $unit->name_ne),
            NameNormalizer::normalize((string) $unit->name_en),
        ]);

        foreach ($names as $name) {
            if ($name === $needle) {
                return 100;
            }
        }

        foreach ($names as $name) {
            if (str_starts_with($name, $needle)) {
                return 80;
            }
        }

        foreach ($names as $name) {
            if (str_contains($name, $needle)) {
                return 60;
            }
        }

        if (str_starts_with($unit->slug, $needle)) {
            return 55;
        }

        if (isset($aliasMatches[$unit->id])) {
            // 30–50, by how close the alias was.
            return 30 + (int) round($aliasMatches[$unit->id] * 20);
        }

        return 0;
    }

    /**
     * Older names, variants and known misspellings, via the trigram index
     * (R5, docs/05 §3.2).
     *
     * This is the only part of the search that needs an index and the only part
     * that can forgive a typo. `%` uses pg_trgm's similarity threshold; the
     * explicit similarity() in the select is what lets a near-miss rank below a
     * close one rather than all aliases scoring alike.
     *
     * @param  list<string>  $openLocalLevelIds
     * @return array<string, float>
     */
    private function aliasMatches(string $needle, array $openLocalLevelIds): array
    {
        if ($openLocalLevelIds === []) {
            return [];
        }

        return AdminUnit::query()
            ->getConnection()
            ->table('admin_unit_aliases')
            ->selectRaw('admin_unit_id, max(similarity(normalized, ?)) as score', [$needle])
            ->whereIn('admin_unit_id', $openLocalLevelIds)
            ->whereRaw('similarity(normalized, ?) > ?', [$needle, self::SIMILARITY_FLOOR])
            ->groupBy('admin_unit_id')
            ->pluck('score', 'admin_unit_id')
            ->map(fn ($score): float => (float) $score)
            ->all();
    }

    /**
     * The published ward of that number in each matching municipality.
     *
     * A municipality with fewer wards than the number asked for simply produces
     * no ward row. Inventing one would send a reader to a 404 from a search
     * result, which reads as the site being broken rather than as the ward not
     * existing.
     *
     * @param  Collection<int, array{unit: AdminUnit, score: int}>  $localLevels
     * @return list<array{unit: AdminUnit, score: int}>
     */
    private function wardsOf(Collection $localLevels, int $wardNumber): array
    {
        $byParent = $localLevels->keyBy(fn (array $match): string => $match['unit']->id);

        $wards = AdminUnit::query()
            ->publiclyVisible()
            ->where('level', AdminLevel::Ward->value)
            ->where('ward_number', $wardNumber)
            ->whereIn('parent_id', $byParent->keys()->all())
            ->with(['parent.parent.parent'])
            ->get();

        return $wards
            ->map(fn (AdminUnit $ward): array => [
                'unit' => $ward,
                'score' => $byParent[$ward->parent_id]['score'],
            ])
            ->all();
    }
}

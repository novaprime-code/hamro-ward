<?php

declare(strict_types=1);

namespace App\Modules\Geography\Queries;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Support\Digits;
use App\Modules\Geography\Support\NameNormalizer;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Find your ward" (HW-E09, FR-GEO-07).
 *
 * The one question the whole site is organised around, and the picker only
 * half answers it: it filters a list the browser already has, which works at
 * four municipalities and stops working at 753 — both because the payload gets
 * large on a connection that is already slow, and because substring matching
 * fails on the way Nepali place names are actually typed.
 *
 * Three things this handles that a `LIKE '%needle%'` does not:
 *
 *  1. **Spelling.** Itahari, इटहरी, Ithari, Itahary. Names are matched on the
 *     normalized alias column, which has a trigram index, so a near miss still
 *     finds the place (docs/06 §5).
 *  2. **Digits in either script.** "इटहरी ४" and "itahari 4" are the same
 *     query. A ward number typed on a phone is usually Latin; the same number
 *     on the office sign is Devanagari.
 *  3. **The ward itself.** A trailing number is not noise to be stripped — it
 *     is the most specific thing in the query. "इटहरी ४" means ward 4, and
 *     landing the reader on the ward rather than the municipality saves the one
 *     tap that the entire product is about.
 *
 * Search is central-only and covers places, not people. Person names live
 * behind a per-municipality database, and a national search over the names of
 * elected officials is a different feature with different consequences — it
 * wants deciding on rather than falling out of a geography query.
 */
final class SearchPlaces
{
    /** Below this, a trigram match is noise rather than a near miss. */
    private const SIMILARITY_FLOOR = 0.3;

    private const LIMIT = 12;

    /**
     * @return Collection<int, array<string, mixed>>  ranked; each row has
     *                                                type, slug_path, names and
     *                                                (for a ward) its number
     */
    public function handle(string $query): Collection
    {
        [$text, $wardNumber] = $this->split($query);

        if ($text === '' && $wardNumber === null) {
            return collect();
        }

        $places = $text === ''
            ? $this->everyOpenLocalLevel()
            : $this->matchLocalLevels($text);

        if ($places->isEmpty()) {
            return collect();
        }

        /*
         * A ward number turns each matching municipality into a ward result
         * where that ward is actually published, and leaves it as a
         * municipality where it is not. Silently dropping the number would
         * send the reader somewhere they did not ask for; silently inventing
         * ward 4 of a municipality whose ward 4 is not published would send
         * them to a 404.
         */
        if ($wardNumber !== null) {
            $wards = $this->wardsOf($places->pluck('id')->all(), $wardNumber);

            if ($wards->isNotEmpty()) {
                return $wards->take(self::LIMIT)->values();
            }
        }

        return $places->take(self::LIMIT)->values();
    }

    /**
     * Splits "इटहरी ४" into a name and a ward number.
     *
     * Only a TRAILING number counts. A leading or embedded one is part of the
     * name as often as not, and guessing wrong here means quietly answering a
     * different question from the one asked.
     *
     * @return array{0: string, 1: int|null}
     */
    private function split(string $query): array
    {
        $text = trim(Digits::toLatin($query));

        if (preg_match('/^(.*?)[\s,\-]*?(\d{1,2})$/u', $text, $matches) === 1) {
            $number = (int) $matches[2];

            if ($number >= 1 && $number <= 99) {
                return [trim($matches[1]), $number];
            }
        }

        return [$text, null];
    }

    /**
     * Local levels that are published, current, and have an active tenant —
     * the same bar the picker uses, because a search result that leads to a 503
     * reads as a broken site rather than as a municipality that is not ready.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function everyOpenLocalLevel(): Collection
    {
        return $this->rows($this->openLocalLevels()->orderBy('u.name_en')->limit(self::LIMIT)->get());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function matchLocalLevels(string $text): Collection
    {
        $needle = NameNormalizer::normalize($text);

        if ($needle === '') {
            return collect();
        }

        /*
         * Ranked by the best trigram similarity among the unit's aliases, with
         * a prefix match on either name treated as a certainty. `similarity`
         * and the GIN index come from pg_trgm, which the tenant template
         * already installs.
         *
         * The alias table is the right thing to match on rather than the name
         * columns: it is where legacy names, misspellings and romanizations
         * live, already normalized, already indexed (docs/05 §3.3).
         */
        $rows = $this->openLocalLevels()
            ->leftJoin('admin_unit_aliases as a', 'a.admin_unit_id', '=', 'u.id')
            ->selectRaw(
                'greatest(
                    coalesce(max(similarity(a.normalized, ?)), 0),
                    case when lower(u.name_ne) like ? or lower(u.name_en) like ? or u.slug like ? then 1 else 0 end
                ) as score',
                [$needle, $needle.'%', $needle.'%', $needle.'%'],
            )
            ->groupBy(
                'u.id', 'u.slug', 'u.name_ne', 'u.name_en', 'u.local_level_type', 's.slug_path',
                'd.name_ne', 'd.name_en', 'pr.name_ne', 'pr.name_en',
            )
            ->havingRaw(
                'greatest(
                    coalesce(max(similarity(a.normalized, ?)), 0),
                    case when lower(u.name_ne) like ? or lower(u.name_en) like ? or u.slug like ? then 1 else 0 end
                ) >= ?',
                [$needle, $needle.'%', $needle.'%', $needle.'%', self::SIMILARITY_FLOOR],
            )
            ->orderByDesc('score')
            ->orderBy('u.name_en')
            ->limit(self::LIMIT)
            ->get();

        return $this->rows($rows);
    }

    /**
     * The shared base: published, current, tenanted local levels with their
     * current slug path.
     *
     * Joined to admin_unit_slugs rather than rebuilt from parent slugs, because
     * the slug path is a stored fact with a history — rebuilding it here would
     * produce a URL that disagrees with the one the redirect table knows about.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function openLocalLevels(): \Illuminate\Database\Query\Builder
    {
        $tenanted = Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->pluck('admin_unit_id')
            ->all();

        return DB::connection((string) config('tenancy.central_connection'))
            ->table('admin_units as u')
            ->join('admin_unit_slugs as s', function ($join): void {
                $join->on('s.admin_unit_id', '=', 'u.id')->where('s.is_current', true);
            })
            ->leftJoin('admin_units as d', 'd.id', '=', 'u.parent_id')
            ->leftJoin('admin_units as pr', 'pr.id', '=', 'd.parent_id')
            ->where('u.level', AdminLevel::LocalLevel->value)
            ->where('u.is_published', true)
            ->whereNull('u.valid_to')
            ->whereIn('u.id', $tenanted === [] ? [''] : $tenanted)
            ->select(
                'u.id', 'u.slug', 'u.name_ne', 'u.name_en', 'u.local_level_type', 's.slug_path',
                'd.name_ne as district_ne', 'd.name_en as district_en',
                'pr.name_ne as province_ne', 'pr.name_en as province_en',
            );
    }

    /**
     * @param  list<string>  $localLevelIds
     * @return Collection<int, array<string, mixed>>
     */
    private function wardsOf(array $localLevelIds, int $wardNumber): Collection
    {
        if ($localLevelIds === []) {
            return collect();
        }

        return DB::connection((string) config('tenancy.central_connection'))
            ->table('admin_units as w')
            ->join('admin_units as p', 'p.id', '=', 'w.parent_id')
            ->join('admin_unit_slugs as s', function ($join): void {
                $join->on('s.admin_unit_id', '=', 'p.id')->where('s.is_current', true);
            })
            ->where('w.level', AdminLevel::Ward->value)
            ->where('w.ward_number', $wardNumber)
            ->where('w.is_published', true)
            ->whereNull('w.valid_to')
            ->whereIn('w.parent_id', $localLevelIds)
            ->orderBy('p.name_en')
            ->leftJoin('admin_units as d', 'd.id', '=', 'p.parent_id')
            ->leftJoin('admin_units as pr', 'pr.id', '=', 'd.parent_id')
            ->select(
                'w.ward_number', 'p.name_ne', 'p.name_en', 'p.local_level_type', 's.slug_path',
                'd.name_ne as district_ne', 'd.name_en as district_en',
                'pr.name_ne as province_ne', 'pr.name_en as province_en',
            )
            ->get()
            ->map(fn (object $row): array => [
                'type' => 'ward',
                'slug_path' => $row->slug_path,
                'ward_number' => (int) $row->ward_number,
                'name' => ['ne' => $row->name_ne, 'en' => $row->name_en],
                'local_level_type' => $row->local_level_type,
                'district' => ['ne' => $row->district_ne, 'en' => $row->district_en],
                'province' => ['ne' => $row->province_ne, 'en' => $row->province_en],
            ]);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Collection $rows): Collection
    {
        return $rows->map(fn (object $row): array => [
            'type' => 'local_level',
            'slug_path' => $row->slug_path,
            'ward_number' => null,
            'name' => ['ne' => $row->name_ne, 'en' => $row->name_en],
            'local_level_type' => $row->local_level_type,
            'district' => ['ne' => $row->district_ne, 'en' => $row->district_en],
            'province' => ['ne' => $row->province_ne, 'en' => $row->province_en],
        ]);
    }
}

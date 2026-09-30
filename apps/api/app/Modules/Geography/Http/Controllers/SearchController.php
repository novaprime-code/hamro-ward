<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Queries\SearchPlaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search?q=…  (HW-E09-F01, FR-GEO-07)
 *
 * Central only, and outside the tenant group on purpose: a search is the one
 * request a visitor makes BEFORE they know which municipality they want, so it
 * cannot require one to have been resolved.
 *
 * Returns the address of each result, not its content. A search result's job is
 * to be the right link; the page behind it is what fetches.
 */
final class SearchController
{
    public function __invoke(Request $request, SearchPlaces $search): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        /*
         * Two characters is short enough for a Devanagari place name — many are
         * two syllables — and long enough that an accidental keystroke does not
         * scan the table. An empty response, not an error: a person who has
         * typed one letter has not made a mistake, they are mid-word.
         */
        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        $results = $search->handle($query)->map(fn (array $result): array => [
            'type' => $result['type'],
            'slug_path' => $this->slugPathOf($result['unit']),
            'ward_number' => $result['ward_number'],
            'name' => $this->nameOf($result['unit']),
            'local_level_type' => $this->localLevelTypeOf($result['unit']),
            'district' => $this->districtOf($result['unit']),
            'province' => $this->provinceOf($result['unit']),
        ]);

        return response()->json(['data' => $results->values()->all()]);
    }

    /**
     * A ward's own name is usually null — it is identified by its number and
     * its municipality — so the ward row carries the municipality's name and
     * lets the client render "Ward 4" from ward_number in the reader's own
     * digits (§16).
     *
     * @return array{ne: string|null, en: string|null}
     */
    private function nameOf(AdminUnit $unit): array
    {
        $named = $unit->level === AdminLevel::Ward ? $unit->parent : $unit;

        return ['ne' => $named?->name_ne, 'en' => $named?->name_en];
    }

    private function localLevelTypeOf(AdminUnit $unit): ?string
    {
        $localLevel = $unit->level === AdminLevel::Ward ? $unit->parent : $unit;

        return $localLevel?->local_level_type?->value;
    }

    /**
     * @return array{ne: string|null, en: string|null}
     */
    private function districtOf(AdminUnit $unit): array
    {
        $district = $unit->level === AdminLevel::Ward ? $unit->parent?->parent : $unit->parent;

        return ['ne' => $district?->name_ne, 'en' => $district?->name_en];
    }

    /**
     * @return array{ne: string|null, en: string|null}
     */
    private function provinceOf(AdminUnit $unit): array
    {
        $province = $unit->level === AdminLevel::Ward
            ? $unit->parent?->parent?->parent
            : $unit->parent?->parent;

        return ['ne' => $province?->name_ne, 'en' => $province?->name_en];
    }

    /**
     * The municipality's path in both cases. A ward result adds its number as a
     * separate field rather than baking it into the path, so the client builds
     * the ward URL the same way it does everywhere else and there is one place
     * that knows what a ward address looks like.
     */
    private function slugPathOf(AdminUnit $unit): string
    {
        $localLevel = $unit->level === AdminLevel::Ward ? $unit->parent : $unit;

        return implode('/', array_filter([
            $localLevel?->parent?->parent?->slug,
            $localLevel?->parent?->slug,
            $localLevel?->slug,
        ]));
    }
}

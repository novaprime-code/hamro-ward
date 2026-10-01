<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers;

use App\Modules\Geography\Queries\SearchPlaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search?q=…  (HW-E09, FR-GEO-07)
 *
 * "Find your ward" — the one question the whole site is organised around.
 *
 * Central only and outside the tenant group, for the obvious reason: a visitor
 * searching for their municipality has not chosen one yet, which is the point
 * of searching.
 *
 * An empty or over-long query returns an empty list rather than a validation
 * error. Nobody types a 200-character municipality name, so the cap is not a
 * rule a reader can trip over — it is a bound on how much work one request can
 * ask of the database, and the honest response to a query that cannot mean
 * anything is that nothing matched it.
 */
final class SearchController
{
    private const MAX_QUERY_LENGTH = 120;

    public function __invoke(Request $request, SearchPlaces $places): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        $results = mb_strlen($query) > self::MAX_QUERY_LENGTH
            ? collect()
            : $places->handle($query);

        return response()->json([
            'data' => $results->all(),
            'meta' => ['query' => $query, 'count' => $results->count()],
        ]);
    }
}

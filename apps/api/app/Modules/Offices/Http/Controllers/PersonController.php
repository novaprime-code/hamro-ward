<?php

declare(strict_types=1);

namespace App\Modules\Offices\Http\Controllers;

use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Offices\Http\Resources\SeatResource;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Queries\CurrentSeatsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One person, and what they hold in this municipality
 * (HW-E05-F02-T02, FR-OFF-05).
 *
 * Until this existed, a held seat on a ward page linked to an anchor on the
 * same page — truthful, because there was nowhere else to go, and a dead end.
 *
 * Scoped to the resolved tenant on purpose. The person record is central,
 * because the same individual turns up in more than one municipality's story
 * over a career, but their holdings are in per-municipality databases. A
 * reader always arrives here from a ward, so the municipality is known; a
 * career across several of them needs a central index that does not exist yet
 * (D-014).
 *
 * What this endpoint does NOT return is the point of the Person model: no
 * gender, caste, ethnicity, religion, date of birth or address. A civic
 * directory needs none of them to say who holds a seat.
 */
final class PersonController
{
    /**
     * GET /api/v1/persons/{province}/{district}/{local_level}/{person}
     */
    public function show(Request $request, CurrentSeatsQuery $seats): JsonResponse
    {
        /** @var AdminUnit $central */
        $central = $request->attributes->get('localLevel');

        $slug = (string) $request->route('person');

        $person = Person::query()->publiclyVisible()->where('slug', $slug)->first();

        if ($person === null) {
            throw new NotFoundHttpException("No person at /{$slug}.");
        }

        $held = $seats->forPerson($person->id);

        /*
         * A published person with no seat in THIS municipality is a 404 here,
         * not an empty page. They may well hold office elsewhere, and a page
         * headed with a municipality's name showing a person who holds nothing
         * in it invites exactly the wrong inference.
         */
        if ($held->isEmpty()) {
            throw new NotFoundHttpException("{$slug} holds no current seat in this municipality.");
        }

        return response()->json([
            'data' => [
                'slug' => $person->slug,
                'name' => ['ne' => $person->full_name_ne, 'en' => $person->full_name_en],
                'local_level' => [
                    'slug_path' => $this->slugPathOf($central),
                    'name' => ['ne' => $central->name_ne, 'en' => $central->name_en],
                    'type' => $central->local_level_type?->value,
                    'district' => ['ne' => $central->parent?->name_ne, 'en' => $central->parent?->name_en],
                    'province' => [
                        'ne' => $central->parent?->parent?->name_ne,
                        'en' => $central->parent?->parent?->name_en,
                    ],
                ],
                'seats' => SeatResource::collection($held)->resolve(),
                /*
                 * Evidence for the person record itself — that this name is
                 * this person — which is separate from evidence for any seat
                 * they hold. Both are reachable; conflating them would let a
                 * verified holding imply a verified identity.
                 */
                'evidence' => ['subject_type' => 'person', 'subject_id' => $person->id],
            ],
        ]);
    }

    private function slugPathOf(AdminUnit $unit): string
    {
        return implode('/', array_filter([
            $unit->parent?->parent?->slug,
            $unit->parent?->slug,
            $unit->slug,
        ]));
    }
}

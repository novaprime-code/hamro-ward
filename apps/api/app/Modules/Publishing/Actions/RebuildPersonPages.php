<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Actions;

use App\Modules\Publishing\Models\PublicEntity;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Recomputes every person page of the current municipality from scratch.
 *
 * The outbox keeps the index current when a holding changes. Some changes
 * that decide whether a page exists are not tenant changes at all — a person
 * published or unpublished centrally, a ward published after onboarding — so
 * this runs on a schedule as well, and is what to run after restoring a
 * database. It is idempotent: an unchanged page keeps its lastmod.
 */
final class RebuildPersonPages
{
    public function __construct(
        private readonly IndexPersonPage $index,
        private readonly TenantManager $tenancy,
    ) {}

    /** @return int pages indexed or re-checked */
    public function handle(): int
    {
        $tenant = $this->tenancy->current() ?? throw new LogicException('RebuildPersonPages runs inside a tenant.');

        $seated = DB::connection((string) config('tenancy.tenant_connection'))
            ->table('v_current_seats')
            ->whereNotNull('person_id')
            ->distinct()
            ->pluck('person_id')
            ->map(fn (mixed $id): string => (string) $id);

        // Pages indexed earlier whose person no longer sits here: re-checking
        // them is what unpublishes them.
        $indexed = PublicEntity::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('entity_type', 'person')
            ->where('is_published', true)
            ->pluck('entity_id')
            ->map(fn (mixed $id): string => (string) $id);

        $people = $seated->merge($indexed)->unique()->values();

        foreach ($people as $personId) {
            $this->index->handle($personId);
        }

        return $people->count();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Provenance\Queries;

use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\TenantAdminUnit;
use App\Modules\Offices\Models\OfficeHolding;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Models\Vacancy;
use App\Modules\Offices\Models\WardOffice;
use App\Modules\Provenance\DataTransferObjects\Evidence;
use App\Modules\Provenance\DataTransferObjects\EvidenceField;
use App\Modules\Provenance\DataTransferObjects\EvidenceItem;
use App\Modules\Provenance\Enums\SourceScope;
use App\Modules\Provenance\Models\BaseSource;
use App\Modules\Provenance\Models\BaseSourceLink;
use App\Modules\Provenance\Models\BaseSourceType;
use App\Modules\Provenance\Models\Source;
use App\Modules\Provenance\Models\SourceLink;
use App\Modules\Provenance\Models\SourceType;
use App\Modules\Provenance\Models\TenantSource;
use App\Modules\Provenance\Models\TenantSourceLink;
use App\Modules\Provenance\Models\TenantSourceType;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Everything we can show about why a fact on this site is claimed
 * (HW-E04-F02, FR-SRC-03, project instructions §3–§4).
 *
 * The badge on a seat row has always said "official source". This is the query
 * behind the page that has to make good on it: which document, published by
 * whom, when, retrieved when, and saying exactly what.
 *
 * Three things it does that a naive "select source_links where subject" would
 * not:
 *
 *  1. **It refuses subjects it has not been told are public.** subject_type and
 *     subject_id arrive from a URL. Without an allowlist and a visibility check
 *     per type, this endpoint reads evidence for unpublished wards and
 *     unpublished people straight back out of the database — the import queue,
 *     in public, one guessed uuid at a time.
 *  2. **It resolves both scopes.** A tenant link points either at a local
 *     source in the tenant database or at a national one in central (D-014,
 *     docs/12 §4.1), and PostgreSQL will not join across the two.
 *  3. **It groups by field and surfaces disagreement.** Two sources asserting
 *     different values for one field is the case the trust model exists for.
 *     Choosing one quietly is the failure; this returns both, ordered by
 *     authority, and says they disagree (§4).
 */
final class EvidenceForSubject
{
    /**
     * Subjects a member of the public may ask about, and which database they
     * live in.
     *
     * An allowlist rather than a denylist, and morph-map keys rather than class
     * names, because these appear in URLs: renaming a PHP class must never
     * change an address someone has shared.
     *
     * @var array<string, SourceScope::*|string>
     */
    private const SUBJECTS = [
        'office_holding' => 'tenant',
        'vacancy' => 'tenant',
        'ward_office' => 'tenant',
        'admin_unit' => 'central',
        'person' => 'central',
        'party' => 'central',
    ];

    /**
     * @param  string  $subjectType  a morph-map key, from the URL
     * @param  string  $subjectId  a uuid, from the URL
     *
     * @throws NotFoundHttpException when the subject is unknown, not public, or
     *                               does not belong to the resolved tenant
     */
    public function handle(string $subjectType, string $subjectId): Evidence
    {
        $scope = self::SUBJECTS[$subjectType] ?? null;

        if ($scope === null) {
            throw new NotFoundHttpException("There is no evidence page for {$subjectType}.");
        }

        $this->assertPubliclyVisible($subjectType, $subjectId);

        $items = $scope === 'central'
            ? $this->centralLinks($subjectType, $subjectId)
            : $this->tenantLinks($subjectType, $subjectId);

        return new Evidence(
            subjectType: $subjectType,
            subjectId: $subjectId,
            record: $items->filter(fn (EvidenceItem $i): bool => $i->fieldPath === null)->values()->all(),
            fields: $this->byField($items->filter(fn (EvidenceItem $i): bool => $i->fieldPath !== null)),
        );
    }

    /**
     * The subject must exist AND be something a visitor is allowed to see.
     *
     * Tenant subjects are checked on the tenant connection, which ResolveTenant
     * has already pointed at the municipality named in the URL — so a holding
     * id belonging to one municipality cannot be read through another's
     * address.
     */
    private function assertPubliclyVisible(string $subjectType, string $subjectId): void
    {
        $visible = match ($subjectType) {
            'office_holding' => $this->constituencyIsPublished(
                OfficeHolding::query()->whereKey($subjectId)->value('constituency_id'),
            ),
            'vacancy' => $this->constituencyIsPublished(
                Vacancy::query()->whereKey($subjectId)->value('constituency_id'),
            ),
            'ward_office' => $this->constituencyIsPublished(
                WardOffice::query()->whereKey($subjectId)->value('ward_id'),
            ),
            'admin_unit' => AdminUnit::query()->publiclyVisible()->whereKey($subjectId)->exists(),
            'person' => Person::query()->publiclyVisible()->whereKey($subjectId)->exists(),
            'party' => Party::query()->publiclyVisible()->whereKey($subjectId)->exists(),
            default => false,
        };

        if (! $visible) {
            /*
             * Deliberately the same answer as "no such id". An unpublished
             * subject has to be indistinguishable from one that does not
             * exist, or this endpoint becomes a way to enumerate what is
             * still being checked.
             */
            throw new NotFoundHttpException('No evidence at that address.');
        }
    }

    private function constituencyIsPublished(mixed $constituencyId): bool
    {
        if (! is_string($constituencyId) || $constituencyId === '') {
            return false;
        }

        return TenantAdminUnit::query()
            ->publiclyVisible()
            ->whereKey($constituencyId)
            ->exists();
    }

    /**
     * @return Collection<int, EvidenceItem>
     */
    private function centralLinks(string $subjectType, string $subjectId): Collection
    {
        $links = SourceLink::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->get();

        return $this->items($links, $this->centralSources($links->pluck('source_id')->unique()->all()));
    }

    /**
     * @return Collection<int, EvidenceItem>
     */
    private function tenantLinks(string $subjectType, string $subjectId): Collection
    {
        $links = TenantSourceLink::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->get();

        /*
         * Two lookups, not one per link. A tenant link's source is in the
         * tenant database or in central depending on its scope, neither can be
         * joined to the other, and a page with ten sources must not become ten
         * round trips.
         */
        [$central, $local] = $links->partition(
            fn (TenantSourceLink $link): bool => $link->source_scope === SourceScope::Central,
        );

        $sources = array_replace(
            $this->centralSources($central->pluck('source_id')->unique()->all()),
            $this->tenantSources($local->pluck('source_id')->unique()->all()),
        );

        return $this->items($links, $sources);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function centralSources(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->shape(
            Source::query()->whereIn('id', $ids)->get(),
            SourceType::query()->get(),
        );
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function tenantSources(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->shape(
            TenantSource::query()->whereIn('id', $ids)->get(),
            TenantSourceType::query()->get(),
        );
    }

    /**
     * @param  Collection<int, covariant BaseSource>  $sources
     * @param  Collection<int, covariant BaseSourceType>  $types
     * @return array<string, array<string, mixed>>
     */
    private function shape(Collection $sources, Collection $types): array
    {
        $byKey = $types->keyBy('key');
        $shaped = [];

        foreach ($sources as $source) {
            $type = $byKey->get($source->source_type_key);

            $shaped[$source->id] = [
                'title' => (string) $source->title,
                'publisher' => $source->publisher,
                'url' => $source->url,
                'type_key' => (string) $source->source_type_key,
                /*
                 * Rank 1 is the Election Commission. An unknown type sorts
                 * LAST rather than first, so a source type missing from a
                 * tenant's replica cannot quietly outrank the ECN.
                 */
                'rank' => (int) ($type?->authority_rank ?? 99),
                'label_ne' => (string) ($type?->label_ne ?? $source->source_type_key),
                'label_en' => (string) ($type?->label_en ?? $source->source_type_key),
                'published_at' => $source->published_at?->toDateString(),
                'retrieved_at' => $source->retrieved_at?->toIso8601String(),
            ];
        }

        return $shaped;
    }

    /**
     * @param  Collection<int, covariant BaseSourceLink>  $links
     * @param  array<string, array<string, mixed>>  $sources
     * @return Collection<int, EvidenceItem>
     */
    private function items(Collection $links, array $sources): Collection
    {
        return $links
            ->map(function (BaseSourceLink $link) use ($sources): ?EvidenceItem {
                $source = $sources[$link->source_id] ?? null;

                /*
                 * A link whose source row has gone is a broken reference, not
                 * evidence. Rendering "official source" with nothing behind it
                 * is the precise claim this page exists to prevent.
                 */
                if ($source === null) {
                    return null;
                }

                return new EvidenceItem(
                    sourceId: (string) $link->source_id,
                    fieldPath: $link->field_path,
                    provenanceType: $link->provenance_type->value,
                    verificationStatus: $link->verification_status->value,
                    assertedValue: $this->readable($link->asserted_value),
                    locator: $link->locator,
                    excerpt: $link->excerpt,
                    title: (string) $source['title'],
                    publisher: $source['publisher'],
                    url: $source['url'],
                    sourceTypeKey: (string) $source['type_key'],
                    authorityRank: (int) $source['rank'],
                    sourceTypeLabelNe: (string) $source['label_ne'],
                    sourceTypeLabelEn: (string) $source['label_en'],
                    publishedAt: $source['published_at'],
                    retrievedAt: $source['retrieved_at'],
                );
            })
            ->filter()
            /*
             * Authority first, then verified before unverified. The order is
             * the argument: a reader scrolling an evidence page should meet
             * the Election Commission before they meet a Facebook post, not
             * because the Facebook post is hidden but because rank is what
             * tells them how to weigh it (§4).
             *
             * One callback returning an array, rather than several: Collection
             * compares the arrays element by element, and a list of callbacks
             * here would be read as column/direction pairs.
             */
            ->sortBy(fn (EvidenceItem $item): array => [
                $item->authorityRank,
                $item->isVerified() ? 0 : 1,
                $item->title,
            ])
            ->values();
    }

    /**
     * asserted_value is jsonb, and arrives decoded or as a JSON string
     * depending on which cast is in force. Anything that is not a scalar is not
     * something a page can show a reader, so it is dropped rather than printed
     * as `{"a":1}` beside a person's name.
     */
    private function readable(mixed $value): ?string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_scalar($decoded) ? (string) $decoded : $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * One group per field, flagged when its sources disagree.
     *
     * "Disagree" is defined narrowly: two or more DISTINCT asserted values for
     * the same field. Two sources saying the same thing is corroboration, and
     * dressing it up as a dispute is its own way of misleading a reader.
     *
     * @param  Collection<int, EvidenceItem>  $items
     * @return list<EvidenceField>
     */
    private function byField(Collection $items): array
    {
        return $items
            ->groupBy(fn (EvidenceItem $item): string => (string) $item->fieldPath)
            ->map(fn (Collection $group, string $field): EvidenceField => new EvidenceField(
                fieldPath: $field,
                inConflict: $group
                    ->map(fn (EvidenceItem $item): ?string => $item->assertedValue)
                    ->filter()
                    ->unique()
                    ->count() > 1,
                items: $group->values()->all(),
            ))
            ->values()
            ->all();
    }
}

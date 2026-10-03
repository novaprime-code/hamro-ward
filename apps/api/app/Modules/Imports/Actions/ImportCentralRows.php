<?php

declare(strict_types=1);

namespace App\Modules\Imports\Actions;

use App\Modules\Geography\Actions\RefreshSlugPaths;
use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Enums\AliasKind;
use App\Modules\Geography\Enums\AliasScript;
use App\Modules\Geography\Enums\CodeScheme;
use App\Modules\Geography\Enums\LocalLevelType;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitAlias;
use App\Modules\Geography\Models\AdminUnitCode;
use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Geography\Support\NameNormalizer;
use App\Modules\Imports\Support\Cells;
use App\Modules\Imports\Support\ImportContext;
use App\Modules\Imports\Support\ImportFile;
use App\Modules\Imports\Support\ImportReport;
use App\Modules\Imports\Support\ImportRow;
use App\Modules\Imports\Support\RowRejected;
use App\Modules\Imports\Support\SubjectRef;
use App\Modules\Offices\Models\Party;
use App\Modules\Offices\Models\Person;
use App\Modules\Offices\Support\PersonSlug;
use App\Modules\Provenance\Enums\SourceScope;
use App\Modules\Provenance\Enums\SourceTypeKey;
use App\Modules\Provenance\Enums\VerificationStatus;
use App\Modules\Provenance\Models\Source;
use App\Modules\Provenance\Models\SourceLink;
use App\Modules\Provenance\Models\TenantSource;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The central half of an import (docs/05 §13): national sources, geography,
 * aliases, parties, persons, and verifications of central facts. Runs inside
 * the caller's central transaction; commits nothing itself.
 *
 * Two things it deliberately does NOT do:
 *
 *  - Publish a place. Publication of a local level and its wards is when a
 *    municipality goes live, which is an operator's decision (D-006), not a
 *    side effect of loading its names. New units arrive unpublished.
 *  - Publish a person or party on the strength of being cited. Only a
 *    VERIFIED record-level source publishes one (FR-SRC-02) — see
 *    publishVerified().
 */
final class ImportCentralRows
{
    /** Source types whose documents are a municipality's own, and so live in its tenant (docs/12 §3). */
    public const LOCAL_SOURCE_TYPES = [SourceTypeKey::LocalLevel, SourceTypeKey::WardOffice];

    private const LEVEL_ORDER = ['country' => 0, 'province' => 1, 'district' => 2, 'local_level' => 3, 'ward' => 4];

    private ImportContext $context;

    public function __construct(private readonly RefreshSlugPaths $refreshSlugPaths) {}

    /** @param  array<string, list<ImportRow>>  $rows  by file name */
    public function handle(ImportContext $context, array $rows): void
    {
        $this->context = $context;
        $central = DB::connection((string) config('tenancy.central_connection'));

        $this->registerSources($rows[ImportFile::Sources->value] ?? []);

        $context->each($this->centralSourceRows($rows[ImportFile::Sources->value] ?? []), $central, $this->source(...));

        // Without a tenant, the tenant half never runs. A municipality's own
        // document would otherwise be skipped without a word.
        if ($context->tenant === null) {
            $context->each($this->localSourceRows($rows[ImportFile::Sources->value] ?? []), $central, function (): never {
                throw RowRejected::in('source_type_key', 'is a municipality\'s own document, which is stored with that municipality: import it with --tenant=<province/district/local-level>.');
            });
        }
        $context->each($this->byLevel($rows[ImportFile::AdminUnits->value] ?? []), $central, $this->adminUnit(...));
        $context->each($rows[ImportFile::Aliases->value] ?? [], $central, $this->alias(...));
        $context->each($rows[ImportFile::Parties->value] ?? [], $central, $this->party(...));
        $context->each($rows[ImportFile::Persons->value] ?? [], $central, $this->person(...));
        $context->each($this->centralVerifications($rows[ImportFile::Verifications->value] ?? []), $central, $this->verification(...));

        $this->publishVerified($central);
    }

    // -- sources --------------------------------------------------------------

    /**
     * Every source in the sheet is placed before any row cites one, so that a
     * national record citing a municipality's notice is refused for that
     * reason — rather than with "no such source", which would send the person
     * fixing it looking for a typo that is not there.
     *
     * @param  list<ImportRow>  $rows
     */
    private function registerSources(array $rows): void
    {
        foreach ($rows as $row) {
            $ref = $row->get('source_ref');
            $type = SourceTypeKey::tryFrom((string) $row->get('source_type_key'));

            if ($ref !== null && $type !== null) {
                $this->context->defineSource($ref, $this->isLocal($type) ? SourceScope::Tenant : SourceScope::Central);
            }
        }
    }

    /**
     * @param  list<ImportRow>  $rows
     * @return list<ImportRow>
     */
    private function centralSourceRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (ImportRow $row): bool => ! $this->isLocal(SourceTypeKey::tryFrom((string) $row->get('source_type_key'))),
        ));
    }

    /**
     * @param  list<ImportRow>  $rows
     * @return list<ImportRow>
     */
    private function localSourceRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (ImportRow $row): bool => $this->isLocal(SourceTypeKey::tryFrom((string) $row->get('source_type_key'))),
        ));
    }

    private function isLocal(?SourceTypeKey $type): bool
    {
        return $type !== null && in_array($type, self::LOCAL_SOURCE_TYPES, true);
    }

    private function source(ImportRow $row): void
    {
        $attributes = self::sourceAttributes($row);
        $id = ImportContext::id('source', (string) $row->get('source_ref'));

        if ($this->context->tenant !== null && TenantSource::query()->whereKey($id)->exists()) {
            throw RowRejected::in('source_type_key', 'this source_ref was loaded earlier as a municipality document; a source cannot move between national and local.');
        }

        $source = Source::query()->find($id) ?? new Source;

        $this->context->persist($source, ['id' => $id, ...$attributes], 'source', tenantSide: false);
    }

    /**
     * Shared with the tenant half, so a local source is validated exactly as a
     * national one is.
     *
     * @return array<string, mixed>
     */
    public static function sourceAttributes(ImportRow $row): array
    {
        Cells::ref($row, 'source_ref');
        $type = Cells::enum($row, 'source_type_key', SourceTypeKey::class, required: true);
        $title = Cells::required($row, 'title');

        if (mb_strlen($title) > 300) {
            throw RowRejected::in('title', 'is longer than 300 characters.');
        }

        // No uploads exist yet (HW-E12), so the address is the only way a
        // reader can reach the document. A source nobody can open is not a
        // source a reader can check.
        $url = Cells::url($row, 'url') ?? throw RowRejected::in('url', 'is required: a reader must be able to open the document.');

        $language = $row->get('language');

        if ($language !== null && ! in_array($language, ['ne', 'en', 'mixed', 'other'], true)) {
            throw RowRejected::in('language', "\"{$language}\" is not one of: ne, en, mixed, other.");
        }

        $asWritten = $row->get('published_as_written');

        if ($asWritten !== null && mb_strlen($asWritten) > 120) {
            throw RowRejected::in('published_as_written', 'is longer than 120 characters.');
        }

        return [
            'source_type_key' => $type->value,
            'title' => $title,
            'publisher' => $row->get('publisher'),
            'url' => $url,
            'published_at' => Cells::date($row, 'published_at'),
            'published_as_written' => $asWritten,
            'retrieved_at' => Cells::dateTime($row, 'retrieved_at', required: true),
            'language' => $language,
            'notes' => $row->get('notes'),
        ];
    }

    // -- geography ------------------------------------------------------------

    /**
     * Parents before children, whatever order the sheet lists them in.
     *
     * @param  list<ImportRow>  $rows
     * @return list<ImportRow>
     */
    private function byLevel(array $rows): array
    {
        usort($rows, fn (ImportRow $a, ImportRow $b): int => [self::LEVEL_ORDER[$a->get('level') ?? ''] ?? 9, $a->line]
            <=> [self::LEVEL_ORDER[$b->get('level') ?? ''] ?? 9, $b->line]);

        return $rows;
    }

    private function adminUnit(ImportRow $row): void
    {
        $level = Cells::enum($row, 'level', AdminLevel::class, required: true);
        $slug = Cells::required($row, 'slug');
        $parentPath = Cells::path($row, 'parent_path', required: false);

        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            throw RowRejected::in('slug', "\"{$slug}\" may contain only lower-case letters, digits and single dashes.");
        }

        if ($row->get('name_ne') === null && $row->get('name_en') === null) {
            throw RowRejected::in('name_ne', 'a unit needs a name in at least one language.');
        }

        $type = Cells::enum($row, 'local_level_type', LocalLevelType::class);

        if (($level === AdminLevel::LocalLevel) !== ($type !== null)) {
            throw RowRejected::in('local_level_type', $level === AdminLevel::LocalLevel
                ? 'is required for a local level.'
                : 'applies only to local levels; leave it empty.');
        }

        $wardNumber = $row->get('ward_number');

        if ($level === AdminLevel::Ward) {
            $number = Cells::int($row, 'ward_number', default: 0, min: 1, max: 99);

            if ((string) $number !== $slug) {
                throw RowRejected::in('slug', "a ward's slug is its number; expected \"{$number}\".");
            }
        } elseif ($wardNumber !== null) {
            throw RowRejected::in('ward_number', 'applies only to wards; leave it empty.');
        }

        $parent = $this->parentFor($level, $parentPath);
        $path = $level === AdminLevel::Country ? null : ltrim(($parentPath ?? '').'/'.$slug, '/');
        $unit = $this->existingUnit($level, $path, $slug);

        $attributes = [
            'name_ne' => $row->get('name_ne'),
            'name_en' => $row->get('name_en'),
            'local_level_type' => $type?->value,
            'ward_number' => $level === AdminLevel::Ward ? (int) $slug : null,
            'valid_from' => Cells::date($row, 'valid_from'),
        ];

        if ($unit === null) {
            $unit = new AdminUnit;
            $attributes = [...$attributes, 'level' => $level->value, 'parent_id' => $parent?->id, 'slug' => $slug];
        }

        $outcome = $this->context->persist($unit, $attributes, 'admin_unit', tenantSide: false);

        if ($outcome === ImportReport::OUTCOME_CREATED) {
            $this->refreshSlugPaths->handle($unit->refresh());
        }

        if (($code = $row->get('cbs_code')) !== null) {
            $existing = AdminUnitCode::query()
                ->where('admin_unit_id', $unit->id)
                ->where('scheme', CodeScheme::CbsCensus2021->value)
                ->first();

            $this->context->persist($existing ?? new AdminUnitCode, [
                'admin_unit_id' => $unit->id,
                'scheme' => CodeScheme::CbsCensus2021->value,
                'code' => $code,
            ], 'admin_unit_code', tenantSide: false);
        }

        if (($sourceRef = $row->get('source_ref')) !== null) {
            $this->context->link(false, 'admin_unit', (string) $unit->id, null, $sourceRef, 'source_ref');
        }
    }

    private function parentFor(AdminLevel $level, ?string $parentPath): ?AdminUnit
    {
        if ($level === AdminLevel::Country) {
            if ($parentPath !== null) {
                throw RowRejected::in('parent_path', 'a country has no parent; leave it empty.');
            }

            return null;
        }

        if ($level === AdminLevel::Province) {
            if ($parentPath !== null) {
                throw RowRejected::in('parent_path', 'paths start at the province; leave parent_path empty for a province.');
            }

            return $this->currentCountry() ?? throw RowRejected::in('level', 'there is no country to put this province in; add a country row first.');
        }

        if ($parentPath === null) {
            throw RowRejected::in('parent_path', 'is required below province level.');
        }

        $parent = $this->unitAt($parentPath)
            ?? throw RowRejected::in('parent_path', "no unit at \"{$parentPath}\". Parents must be in this file or already loaded.");

        if ($parent->level !== $level->parentLevel()) {
            throw RowRejected::in('parent_path', sprintf('a %s goes inside a %s; "%s" is a %s.', $level->value, $level->parentLevel()?->value, $parentPath, $parent->level->value));
        }

        return $parent;
    }

    private function existingUnit(AdminLevel $level, ?string $path, string $slug): ?AdminUnit
    {
        if ($level === AdminLevel::Country) {
            $country = $this->currentCountry();

            if ($country !== null && $country->slug !== $slug) {
                throw RowRejected::in('slug', "the country already exists as \"{$country->slug}\"; there is only one.");
            }

            return $country;
        }

        $unit = $this->unitAt((string) $path);

        if ($unit !== null && $unit->level !== $level) {
            throw RowRejected::in('level', "\"{$path}\" already exists as a {$unit->level->value}.");
        }

        return $unit;
    }

    private function currentCountry(): ?AdminUnit
    {
        return AdminUnit::query()->where('level', AdminLevel::Country->value)->whereNull('valid_to')->first();
    }

    private function unitAt(string $path): ?AdminUnit
    {
        $id = AdminUnitSlug::query()->where('slug_path', $path)->where('is_current', true)->value('admin_unit_id');

        return $id === null ? null : AdminUnit::query()->whereNull('valid_to')->find($id);
    }

    private function alias(ImportRow $row): void
    {
        $path = (string) Cells::path($row, 'unit_path');
        $alias = Cells::required($row, 'alias');
        $unit = $this->unitAt($path) ?? throw RowRejected::in('unit_path', "no unit at \"{$path}\".");

        if (mb_strlen($alias) > 200) {
            throw RowRejected::in('alias', 'is longer than 200 characters.');
        }

        // Script is mechanical, so a blank cell is worked out rather than
        // refused. Kind is a judgement — a legacy name versus a misspelling —
        // and is left to the person who knows.
        $script = Cells::enum($row, 'script', AliasScript::class)
            ?? (preg_match('/\p{Devanagari}/u', $alias) === 1 ? AliasScript::Devanagari : AliasScript::Latin);
        $kind = Cells::enum($row, 'kind', AliasKind::class, required: true);

        $existing = AdminUnitAlias::query()
            ->where('admin_unit_id', $unit->id)
            ->where('normalized', NameNormalizer::normalize($alias))
            ->first();

        $this->context->persist($existing ?? new AdminUnitAlias, [
            'admin_unit_id' => $unit->id,
            'alias' => $alias,
            'script' => $script->value,
            'kind' => $kind->value,
        ], 'admin_unit_alias', tenantSide: false);
    }

    // -- parties and persons ----------------------------------------------------

    private function party(ImportRow $row): void
    {
        $ref = (string) Cells::ref($row, 'party_ref');
        $id = ImportContext::id('party', $ref);

        if ($row->get('name_ne') === null && $row->get('name_en') === null) {
            throw RowRejected::in('name_ne', 'a party needs a name in at least one language.');
        }

        foreach (['abbreviation_ne', 'abbreviation_en'] as $column) {
            if (mb_strlen((string) $row->get($column)) > 40) {
                throw RowRejected::in($column, 'is longer than 40 characters.');
            }
        }

        $party = Party::query()->find($id);
        $attributes = [
            'name_ne' => $row->get('name_ne'),
            'name_en' => $row->get('name_en'),
            'abbreviation_ne' => $row->get('abbreviation_ne'),
            'abbreviation_en' => $row->get('abbreviation_en'),
        ];

        if ($party === null) {
            $party = new Party;
            $attributes = ['id' => $id, 'slug' => $this->partySlug($row, $ref, $id), ...$attributes];
        } else {
            $this->warnOnRename($row, 'party', $ref, [$party->name_ne, $party->name_en], [$row->get('name_ne'), $row->get('name_en')]);
        }

        $this->context->persist($party, $attributes, 'party', tenantSide: false);
        $this->context->touchParty($id);

        if (($sourceRef = $row->get('source_ref')) !== null) {
            $this->context->link(false, 'party', $id, null, $sourceRef, 'source_ref');
        }
    }

    /** Set once, on creation: a party's address does not move when its name is corrected. */
    private function partySlug(ImportRow $row, string $ref, string $id): string
    {
        $slug = Str::slug((string) ($row->get('name_en') ?? $ref)) ?: Str::slug($ref) ?: 'party';
        $slug = Str::limit($slug, 100, '');

        if (Party::query()->where('slug', $slug)->whereNull('valid_to')->exists()) {
            $slug .= '-'.substr(str_replace('-', '', $id), 0, 6);
        }

        return $slug;
    }

    private function person(ImportRow $row): void
    {
        $ref = (string) Cells::ref($row, 'person_ref');
        $id = ImportContext::id('person', $ref);

        if ($row->get('full_name_ne') === null && $row->get('full_name_en') === null) {
            throw RowRejected::in('full_name_ne', 'a person needs a name in at least one language.');
        }

        $person = Person::query()->find($id);
        $attributes = ['full_name_ne' => $row->get('full_name_ne'), 'full_name_en' => $row->get('full_name_en')];

        if ($person === null) {
            $person = new Person;
            $attributes = ['id' => $id, 'slug' => $this->personSlug($row->get('full_name_en'), $id), ...$attributes];
        } else {
            if ($person->isMerged()) {
                throw RowRejected::in('person_ref', "\"{$ref}\" was merged into another person record. Use the ref of the record that was kept.");
            }

            $this->warnOnRename($row, 'person', $ref, [$person->full_name_ne, $person->full_name_en], [$row->get('full_name_ne'), $row->get('full_name_en')]);
        }

        $this->context->persist($person, $attributes, 'person', tenantSide: false);
        $this->context->touchPerson($id);

        if (($sourceRef = $row->get('source_ref')) !== null) {
            $this->context->link(false, 'person', $id, null, $sourceRef, 'source_ref');
        }
    }

    /**
     * Derived from the id rather than random, so a dry run and the real run
     * report the same address, and set once: a person page's URL does not move
     * when the spelling of their name is corrected.
     */
    private function personSlug(?string $nameEn, string $id): string
    {
        $hex = str_replace('-', '', $id);
        $slug = PersonSlug::for($nameEn, substr(base_convert(substr($hex, 0, 10), 16, 36), 0, 4));

        return Person::query()->where('slug', $slug)->exists()
            ? PersonSlug::for($nameEn, substr(base_convert(substr($hex, 0, 12), 16, 36), 0, 8))
            : $slug;
    }

    /**
     * Refs are global, so the same ref in two municipalities' sheets is the
     * same row. A re-import that changes a name is usually a corrected
     * spelling — and occasionally a ref reused for somebody else, which would
     * silently turn one person into another. The reviewer decides which.
     *
     * @param  array{0: ?string, 1: ?string}  $before
     * @param  array{0: ?string, 1: ?string}  $after
     */
    private function warnOnRename(ImportRow $row, string $kind, string $ref, array $before, array $after): void
    {
        if ($before === $after) {
            return;
        }

        $this->context->report->warning($row->file, $row->line, null, sprintf(
            '%s "%s" is renamed from "%s" to "%s". Fine if this corrects a spelling; if this ref was reused for someone else, give them a new ref.',
            $kind,
            $ref,
            implode(' / ', array_filter($before)),
            implode(' / ', array_filter($after)),
        ));
    }

    // -- verifications ----------------------------------------------------------

    /**
     * @param  list<ImportRow>  $rows
     * @return list<ImportRow>
     */
    private function centralVerifications(array $rows): array
    {
        return array_values(array_filter($rows, function (ImportRow $row): bool {
            $type = strstr((string) $row->get('subject_ref'), ':', before_needle: true);

            return ! in_array($type, ['ward_office', 'office_holding', 'vacancy'], true) || $this->context->tenant === null;
        }));
    }

    private function verification(ImportRow $row): void
    {
        $subject = SubjectRef::parse($row);

        if ($subject->isTenantSubject()) {
            throw RowRejected::in('subject_ref', 'verifies a record inside a municipality, so the import needs --tenant.');
        }

        if ($row->get('field') !== null) {
            throw RowRejected::in('field', "only office holdings carry per-field sources; leave it empty for a {$subject->type}.");
        }

        [$verifiedBy, $reviewedBy, $verifiedOn] = Cells::verifiers($row);
        $sourceRef = (string) Cells::ref($row, 'source_ref');
        $source = $this->context->source($sourceRef) ?? throw RowRejected::in('source_ref', "no source \"{$sourceRef}\".");

        $subjectId = match ($subject->type) {
            'person' => ImportContext::id('person', $subject->parts[0]),
            'party' => ImportContext::id('party', $subject->parts[0]),
            'admin_unit' => $this->unitAt($subject->parts[0])?->id,
            default => null,
        } ?? throw RowRejected::in('subject_ref', "no unit at \"{$subject->parts[0]}\".");

        $link = SourceLink::query()
            ->where('source_id', $source['id'])
            ->where('subject_type', $subject->type)
            ->where('subject_id', $subjectId)
            ->whereNull('field_path')
            ->first()
            ?? throw RowRejected::in('subject_ref', "{$subject->display()} does not cite \"{$sourceRef}\". Put the source in that record's source_ref first; a verification confirms a citation, it does not create one.");

        $outcome = $this->context->verify($link, $verifiedBy, $reviewedBy, $verifiedOn, tenantSide: false);

        if ($subject->type === 'person') {
            $this->context->touchPerson($subjectId);
        } elseif ($subject->type === 'party') {
            $this->context->touchParty($subjectId);
        }

        $this->context->report->verification($subject->display(), '—', $sourceRef, $verifiedBy, $reviewedBy, $verifiedOn->toDateString(), $outcome);
    }

    // -- publication ------------------------------------------------------------

    /**
     * FR-SRC-02: a person or party becomes public once a verified source backs
     * the record itself. Not before — a name on a ward page with nothing
     * behind it already reads "not yet verified", and a person page would
     * present the same unverified record as if it stood on its own.
     *
     * Only ever raises the flag. Unpublishing is a decision with consequences
     * for pages people have already shared, and does not happen as a side
     * effect of a spreadsheet.
     */
    private function publishVerified(Connection $central): void
    {
        foreach ([Person::class => $this->context->touchedPersons(), Party::class => $this->context->touchedParties()] as $model => $ids) {
            $subjectType = $model === Person::class ? 'person' : 'party';

            $verified = SourceLink::query()
                ->where('subject_type', $subjectType)
                ->whereIn('subject_id', $ids)
                ->whereNull('field_path')
                ->where('verification_status', VerificationStatus::Verified->value)
                ->pluck('subject_id')
                ->unique()
                ->all();

            $records = $model::query()->whereIn('id', $verified)->where('is_published', false)->get();

            foreach ($records as $record) {
                if ($record->getAttribute('merged_into_person_id') !== null) {
                    continue;
                }

                $central->transaction(function () use ($record, $subjectType): void {
                    $record->forceFill(['is_published' => true, 'published_at' => now()])->save();
                    $this->context->record(false, "{$subjectType}.published", $subjectType, (string) $record->getKey(), [
                        'before' => ['is_published' => false],
                        'after' => ['is_published' => true],
                    ]);
                    $this->context->report->count("{$subjectType} published", ImportReport::OUTCOME_UPDATED);
                });
            }
        }
    }
}

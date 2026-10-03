<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Provenance\Enums\SourceScope;
use App\Modules\Provenance\Enums\VerificationStatus;
use App\Modules\Provenance\Models\BaseSourceLink;
use App\Modules\Provenance\Models\Source;
use App\Modules\Provenance\Models\SourceLink;
use App\Modules\Provenance\Models\SourceType;
use App\Modules\Provenance\Models\TenantSource;
use App\Modules\Provenance\Models\TenantSourceLink;
use App\Modules\Tenancy\Models\Tenant;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * State shared by every step of one import run: the report, what the batch
 * has defined so far, and the three operations every step needs — run a row in
 * isolation, write a record with its audit event, and attach a source.
 *
 * ---------------------------------------------------------------------------
 * Identifiers. The sheet names people, parties and sources by its own refs
 * (`person_ref`, `party_ref`, `source_ref`). Those become database ids through
 * UUIDv5 over a fixed namespace, so the same ref is the same row on every run —
 * which is what makes a re-run an update rather than a second copy of
 * everyone. Refs are therefore GLOBAL: `p12` in one municipality's sheet and
 * `p12` in another's are the same person. The report flags any re-import that
 * would rename a person or party, which is what a reused ref looks like.
 *
 * Office holdings, vacancies and ward offices have real natural keys
 * (docs/05 §13) and are found by them, so rows that existed before the
 * importer — or were entered by hand — are updated rather than duplicated.
 * ---------------------------------------------------------------------------
 */
final class ImportContext
{
    /** Fixed forever: changing it would turn every re-import into a fresh copy of everyone. */
    private const NAMESPACE = '4f1c3c86-6b0e-5d0a-9c62-2a3c1f0e8b17';

    /** @var array<string, array{scope: SourceScope, id: string}> source_ref => where it lives */
    private array $sources = [];

    /** @var array<string, string> source_type_key => default provenance type */
    private array $provenanceTypes = [];

    /** @var array<string, true> person ids written or verified in this run */
    private array $touchedPersons = [];

    /** @var array<string, true> party ids written or verified in this run */
    private array $touchedParties = [];

    /** @var array<string, true> subject|field pairs already warned about */
    private array $disagreementsReported = [];

    public function __construct(
        public readonly ImportReport $report,
        public readonly ?Tenant $tenant,
        private readonly RecordAuditEvent $audit,
    ) {}

    public static function id(string $kind, string $ref): string
    {
        return Uuid::uuid5(self::NAMESPACE, $kind.':'.$ref)->toString();
    }

    /**
     * A stable pseudo-identifier for a named volunteer, until staff accounts
     * exist (HW-E13). verified_by must hold a uuid when a link is verified,
     * and the people verifying the pilot sheet have no staff_users row yet.
     * The name itself is recorded in the import.completed audit event, which
     * is how this id is traced back to a person.
     */
    public static function staffId(string $name): string
    {
        return self::id('staff-name', mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? $name));
    }

    // -- rows -------------------------------------------------------------

    /**
     * Runs one row inside a savepoint. A row that fails rolls back alone and
     * is reported; the rest are still checked, so one run lists every problem.
     * Anything that is not a recognisable data problem is a bug, and is
     * allowed to stop the whole import.
     *
     * @param  list<ImportRow>  $rows
     * @param  Closure(ImportRow): void  $write
     */
    public function each(array $rows, Connection $connection, Closure $write): void
    {
        $seen = [];

        foreach ($rows as $row) {
            $key = $row->key();

            if (isset($seen[$key])) {
                $this->report->error($row->file, $row->line, null, "repeats the row at row {$seen[$key]} "
                    .'(same '.implode(', ', $row->file->keyColumns()).'). Keep one; otherwise which one wins depends on row order.');

                continue;
            }

            $seen[$key] = $row->line;

            try {
                $connection->transaction(fn () => $write($row));
            } catch (RowRejected $rejected) {
                $this->report->error($row->file, $row->line, $rejected->column, $rejected->getMessage());
            } catch (QueryException $exception) {
                $this->report->error($row->file, $row->line, null, $this->explain($exception));
            } catch (RuntimeException $exception) {
                if (! str_starts_with($exception::class, 'App\\Modules\\')) {
                    throw $exception;
                }

                $this->report->error($row->file, $row->line, null, $exception->getMessage());
            }
        }
    }

    /**
     * The database's own refusal, in words the person fixing the sheet can act
     * on. The constraints named here are the ones a real sheet hits; anything
     * else is passed through with the connection details stripped.
     */
    private function explain(QueryException $exception): string
    {
        $message = (string) ($exception->errorInfo[2] ?? $exception->getMessage());
        $message = trim(strtok($message, "\n") ?: $message);
        $message = (string) preg_replace('/^ERROR:\s*/', '', $message);

        return match (true) {
            str_contains($message, 'office_holdings_no_seat_overlap') => 'someone else holds this seat over an overlapping period. '
                .'If they left office, give their holding an end_date and end_reason first.',
            str_contains($message, 'vacancies_no_seat_overlap') => 'the seat is already recorded as vacant over an overlapping period.',
            str_contains($message, 'parties_current_slug') => 'another party already uses this name\'s web address (slug).',
            str_contains($message, 'admin_unit_codes_scheme_code_unique') => 'another unit already has this cbs_code.',
            default => 'the database refused this row: '.$message,
        };
    }

    // -- records ----------------------------------------------------------

    /**
     * Writes a record and its audit event, and counts the outcome.
     *
     * Attributes are written with forceFill: the importer is not user input,
     * and mass assignment would silently drop anything outside a model's
     * $fillable — verification columns among them — and report success
     * (docs/DEVELOPMENT.md, "traps"). A row whose values already match is not
     * saved at all, so a re-run is a no-op rather than a fresh round of
     * updated_at stamps and audit events.
     *
     * @param  array<string, mixed>  $attributes
     * @return ImportReport::OUTCOME_*
     */
    public function persist(
        Model $model,
        array $attributes,
        string $subjectType,
        bool $tenantSide,
        ?string $countAs = null,
        ?string $updateAction = null,
    ): string {
        $countAs ??= $subjectType;

        $isNew = ! $model->exists;

        $model->forceFill($attributes);

        if (! $isNew && ! $model->isDirty()) {
            $this->report->count($countAs, ImportReport::OUTCOME_UNCHANGED);

            return ImportReport::OUTCOME_UNCHANGED;
        }

        $dirty = array_keys($model->getDirty());
        $before = $isNew ? null : $this->plain(array_intersect_key($model->getRawOriginal(), array_flip($dirty)));

        $model->save();

        $after = $this->plain(array_intersect_key($model->getAttributes(), array_flip($dirty)));
        unset($after['created_at'], $after['updated_at']);

        $outcome = $isNew ? ImportReport::OUTCOME_CREATED : ImportReport::OUTCOME_UPDATED;

        $action = $outcome === ImportReport::OUTCOME_UPDATED && $updateAction !== null
            ? $updateAction
            : "{$subjectType}.{$outcome}";

        $this->record($tenantSide, $action, $subjectType, (string) $model->getKey(), [
            'before' => $before,
            'after' => $after,
        ]);

        $this->report->count($countAs, $outcome);

        return $outcome;
    }

    /** @param  array<string, mixed>|null  $changes */
    public function record(bool $tenantSide, string $action, ?string $subjectType, ?string $subjectId, ?array $changes): void
    {
        $tenantSide
            ? $this->audit->tenant(ActorType::Importer, $action, $subjectType, $subjectId, $changes, $this->report->runId)
            : $this->audit->central(ActorType::Importer, $action, $subjectType, $subjectId, $changes, $this->report->runId);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function plain(array $values): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            default => $value,
        }, $values);
    }

    // -- sources ------------------------------------------------------------

    public function defineSource(string $ref, SourceScope $scope): void
    {
        $this->sources[$ref] = ['scope' => $scope, 'id' => self::id('source', $ref)];
    }

    /**
     * Where a source_ref lives: defined by this batch, or loaded by an earlier
     * one in either database.
     *
     * @return array{scope: SourceScope, id: string}|null
     */
    public function source(string $ref): ?array
    {
        if (isset($this->sources[$ref])) {
            return $this->sources[$ref];
        }

        $id = self::id('source', $ref);

        if (Source::query()->whereKey($id)->exists()) {
            return $this->sources[$ref] = ['scope' => SourceScope::Central, 'id' => $id];
        }

        if ($this->tenant !== null && TenantSource::query()->whereKey($id)->exists()) {
            return $this->sources[$ref] = ['scope' => SourceScope::Tenant, 'id' => $id];
        }

        return null;
    }

    /**
     * Attaches a source to a record or one field of it (docs/05 §4.3), and
     * returns the link.
     *
     * The link starts unverified. Verification is a separate statement by two
     * named people, made in verifications.csv; citing a source is not the same
     * as having checked it (D-002).
     */
    public function link(
        bool $tenantSide,
        string $subjectType,
        string $subjectId,
        ?string $fieldPath,
        string $sourceRef,
        string $column,
        mixed $assertedValue = null,
    ): BaseSourceLink {
        $source = $this->source($sourceRef);

        if ($source === null) {
            throw RowRejected::in($column, "no source \"{$sourceRef}\" in sources.csv or in the database.");
        }

        if (! $tenantSide && $source['scope'] === SourceScope::Tenant) {
            throw RowRejected::in($column, "source \"{$sourceRef}\" is a municipality document, so it cannot back a national "
                .'record such as a person, party or place. Cite a national source, or record it under a national source type.');
        }

        $model = $tenantSide ? TenantSourceLink::class : SourceLink::class;

        $query = $model::query()
            ->where('source_id', $source['id'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->when($fieldPath === null, fn ($q) => $q->whereNull('field_path'), fn ($q) => $q->where('field_path', $fieldPath));

        if ($tenantSide) {
            $query->where('source_scope', $source['scope']->value);
        }

        /** @var BaseSourceLink $link */
        $link = $query->first() ?? new $model;

        $attributes = [
            'source_id' => $source['id'],
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'field_path' => $fieldPath,
            'provenance_type' => $this->provenanceTypeFor($source),
            'asserted_value' => $assertedValue,
        ];

        if ($tenantSide) {
            $attributes['source_scope'] = $source['scope']->value;
        }

        $this->persist($link, $attributes, 'source_link', $tenantSide);

        if ($fieldPath !== null) {
            $this->warnIfSourcesDisagree($link, $tenantSide);
        }

        return $link;
    }

    /**
     * Marks an existing link verified by two named people (D-002, docs/05 §4.3:
     * "the verifier and reviewer names come from the import sheet").
     *
     * @return ImportReport::OUTCOME_*
     */
    public function verify(BaseSourceLink $link, string $verifiedBy, string $reviewedBy, Carbon $verifiedOn, bool $tenantSide): string
    {
        return $this->persist($link, [
            'verification_status' => VerificationStatus::Verified->value,
            'verified_by' => self::staffId($verifiedBy),
            'second_approved_by' => self::staffId($reviewedBy),
            'verified_at' => $verifiedOn,
        ], 'source_link', $tenantSide, countAs: 'verification', updateAction: 'source_link.verified');
    }

    /** @param  array{scope: SourceScope, id: string}  $source */
    private function provenanceTypeFor(array $source): string
    {
        $model = $source['scope'] === SourceScope::Central ? Source::class : TenantSource::class;
        $typeKey = $model::query()->toBase()->where('id', $source['id'])->value('source_type_key');

        // Defined by sources.csv but not written: its own row was refused, and
        // the report already says why. Say so here too, rather than pointing
        // the reader at a source that looks present in the sheet.
        if ($typeKey === null) {
            throw RowRejected::row('cites a source whose row in sources.csv could not be loaded; fix that row first.');
        }

        // toBase(): value() through the model applies its enum cast, and an
        // enum is not the string the link column takes.
        return $this->provenanceTypes[$typeKey] ??= (string) SourceType::query()
            ->toBase()
            ->where('key', $typeKey)
            ->value('default_provenance_type');
    }

    /**
     * Two sources giving different values for one field are both kept — the
     * platform shows a disagreement rather than choosing quietly — but the
     * reviewer should know before the import runs, not find out from the ward
     * page.
     */
    private function warnIfSourcesDisagree(BaseSourceLink $link, bool $tenantSide): void
    {
        $key = $link->subject_type.'|'.$link->subject_id.'|'.$link->field_path;

        if (isset($this->disagreementsReported[$key])) {
            return;
        }

        $model = $tenantSide ? TenantSourceLink::class : SourceLink::class;

        $values = $model::query()
            ->where('subject_type', $link->subject_type)
            ->where('subject_id', $link->subject_id)
            ->where('field_path', $link->field_path)
            ->whereNotNull('asserted_value')
            ->distinct()
            ->pluck('asserted_value')
            ->map(fn (mixed $value): string => is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE))
            ->unique()
            ->values();

        if ($values->count() > 1) {
            $this->disagreementsReported[$key] = true;
            $this->report->note(sprintf(
                'Sources disagree about %s of %s %s: %s. Both are kept, and the public page will show the disagreement.',
                $link->field_path,
                $link->subject_type,
                $link->subject_id,
                $values->map(fn (string $value): string => '"'.$value.'"')->implode(' vs '),
            ));
        }
    }

    // -- publication --------------------------------------------------------

    public function touchPerson(string $id): void
    {
        $this->touchedPersons[$id] = true;
    }

    public function touchParty(string $id): void
    {
        $this->touchedParties[$id] = true;
    }

    /** @return list<string> */
    public function touchedPersons(): array
    {
        return array_keys($this->touchedPersons);
    }

    /** @return list<string> */
    public function touchedParties(): array
    {
        return array_keys($this->touchedParties);
    }
}

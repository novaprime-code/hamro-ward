<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Geography\Enums\AdminLevel;
use App\Modules\Geography\Models\AdminUnit;
use App\Modules\Geography\Models\AdminUnitSlug;
use App\Modules\Offices\Models\Position;
use App\Modules\Provenance\Models\SourceType;
use App\Modules\Tenancy\Exceptions\ReferenceDataException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

/**
 * Copies central reference data into one tenant database (docs/12 §9,
 * HW-E29-F02-T02).
 *
 * PostgreSQL cannot join across databases. Anything a tenant query needs inside
 * a WHERE or a JOIN therefore has to exist inside the tenant, and three things
 * qualify:
 *
 *   source_types   the authority ranking every provenance badge reads
 *   positions      the seat catalogue office_holdings and v_current_seats join
 *   admin_units    this local level's slice of the hierarchy — and only that
 *
 * The geography copy is a SUBTREE, not a copy of the table: country, province,
 * district, this local level, its wards. A tenant database that cannot name the
 * other 752 local levels cannot leak them.
 *
 * Everything else that crosses the boundary — persons, parties, national
 * sources — is fetched by id at read time instead (D-014). The difference is
 * cadence: reference data changes on a deploy, people change every day, and a
 * replica of something that changes every day is a stale answer waiting to be
 * served.
 *
 * Writes go through the owner connection because hw_app holds SELECT on these
 * tables and nothing more. The whole sync is one transaction: a half-copied
 * hierarchy is worse than an old one.
 */
final class SyncTenantReferenceData
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{source_types: int, positions: int, admin_units: int, reference_version: int}
     */
    public function handle(Tenant $tenant): array
    {
        $sourceTypes = $this->sourceTypeRows();
        $positions = $this->positionRows();
        $units = $this->adminUnitRows($tenant);

        $this->tenancy->run($tenant, function () use ($sourceTypes, $positions, $units): void {
            $connection = $this->ownerConnection();

            $connection->transaction(function () use ($connection, $sourceTypes, $positions, $units): void {
                $this->replicate($connection, 'source_types', 'key', $sourceTypes);
                $this->replicate($connection, 'positions', 'key', $positions);
                $this->replicate($connection, 'admin_units', 'id', $units);
            });
        }, allowInactive: true);

        $tenant->forceFill([
            'reference_version' => (int) $tenant->reference_version + 1,
        ])->save();

        return [
            'source_types' => count($sourceTypes),
            'positions' => count($positions),
            'admin_units' => count($units),
            'reference_version' => (int) $tenant->reference_version,
        ];
    }

    /**
     * Upsert every row, then delete whatever the central table no longer has.
     *
     * A delete that fails because a holding still points at the row is the
     * correct outcome: a ward cannot quietly disappear from under its elected
     * representatives. The transaction rolls back and the operator is told.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replicate(ConnectionInterface $connection, string $table, string $key, array $rows): void
    {
        if ($rows === []) {
            $connection->table($table)->delete();

            return;
        }

        $columns = array_keys($rows[0]);
        $updatable = array_values(array_diff($columns, [$key]));

        $connection->table($table)->upsert($rows, [$key], $updatable);

        $connection->table($table)
            ->whereNotIn($key, array_column($rows, $key))
            ->delete();
    }

    /** @return list<array<string, mixed>> */
    private function sourceTypeRows(): array
    {
        $now = now();

        return SourceType::query()
            ->orderBy('authority_rank')
            ->get()
            ->map(fn (SourceType $type): array => [
                'key' => $type->key,
                'authority_rank' => $type->authority_rank,
                'label_ne' => $type->label_ne,
                'label_en' => $type->label_en,
                'default_provenance_type' => $this->scalar($type->default_provenance_type),
                'synced_at' => $now,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function positionRows(): array
    {
        $now = now();

        return Position::query()
            ->orderBy('key')
            ->get()
            ->map(fn (Position $position): array => [
                'key' => $position->key,
                'title_ne' => $position->title_ne,
                'title_en' => $position->title_en,
                'body' => $this->scalar($position->body),
                'constituency_level' => $this->scalar($position->constituency_level),
                'seat_category' => $this->scalar($position->seat_category),
                'election_method' => $this->scalar($position->election_method),
                'appointment_type' => $this->scalar($position->appointment_type),
                'applies_to_local_level_types' => $this->textArray($position->applies_to_local_level_types),
                'seats_per_constituency' => json_encode($position->seats_per_constituency, JSON_THROW_ON_ERROR),
                'ballot_order' => $position->ballot_order,
                'valid_from' => $position->valid_from?->toDateString(),
                'valid_to' => $position->valid_to?->toDateString(),
                'synced_at' => $now,
            ])
            ->values()
            ->all();
    }

    /**
     * The tenant's path through the hierarchy: its local level, every ancestor
     * of it, and every ward under it — current and closed alike, because a
     * closed ward still owns the issues reported in it.
     *
     * @return list<array<string, mixed>>
     */
    private function adminUnitRows(Tenant $tenant): array
    {
        $localLevel = AdminUnit::query()->find($tenant->admin_unit_id);

        if ($localLevel === null) {
            throw ReferenceDataException::misconfiguredTenant(
                (string) $tenant->tenant_key,
                'it points at admin unit '.(string) $tenant->admin_unit_id.', which does not exist',
            );
        }

        if ($localLevel->level !== AdminLevel::LocalLevel) {
            throw ReferenceDataException::misconfiguredTenant(
                (string) $tenant->tenant_key,
                'a tenant must be a local level, not a '.(string) $this->scalar($localLevel->level),
            );
        }

        $ids = array_values(array_unique([
            ...$localLevel->ancestor_ids,
            $localLevel->id,
        ]));

        $units = AdminUnit::query()
            ->whereIn('id', $ids)
            ->orWhere('parent_id', $localLevel->id)
            ->get();

        $slugPaths = $this->currentSlugPaths($units->pluck('id')->all());
        $now = now();

        return $units
            ->map(fn (AdminUnit $unit): array => [
                'id' => $unit->id,
                'level' => $this->scalar($unit->level),
                'parent_id' => $unit->parent_id,
                'local_level_type' => $this->scalar($unit->local_level_type),
                'ward_number' => $unit->ward_number,
                'slug' => $unit->slug,
                'slug_path' => $slugPaths[$unit->id] ?? null,
                'name_ne' => $unit->name_ne,
                'name_en' => $unit->name_en,
                'is_published' => $unit->is_published,
                'published_at' => $unit->published_at,
                'valid_from' => $unit->valid_from?->toDateString(),
                'valid_to' => $unit->valid_to?->toDateString(),
                'ancestor_ids' => $this->textArray($unit->ancestor_ids),
                'synced_at' => $now,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $unitIds
     * @return array<string, string>
     */
    private function currentSlugPaths(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        /** @var Collection<int, AdminUnitSlug> $slugs */
        $slugs = AdminUnitSlug::query()
            ->whereIn('admin_unit_id', $unitIds)
            ->where('is_current', true)
            ->get();

        return $slugs->pluck('slug_path', 'admin_unit_id')->all();
    }

    private function ownerConnection(): ConnectionInterface
    {
        return $this->db->connection((string) config('tenancy.tenant_owner_connection'));
    }

    /** Enums arrive from casts; the replica stores their backing values. */
    private function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    /**
     * A PostgreSQL array literal. The query builder binds a PHP array as a
     * parameter list, which is not what an array column wants.
     *
     * @param  array<int, string>  $values
     */
    private function textArray(array $values): string
    {
        if ($values === []) {
            return '{}';
        }

        $quoted = array_map(
            static fn (mixed $item): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $item).'"',
            $values,
        );

        return '{'.implode(',', $quoted).'}';
    }
}

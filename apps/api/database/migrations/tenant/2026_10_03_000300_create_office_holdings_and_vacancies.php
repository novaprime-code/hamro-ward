<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| TENANT — who holds which seat, which seats are vacant, and the derived view
| that answers "who represents ward 4?" (docs/05 §5.4–5.5, R12, R13, FR-OFF-02,
| FR-OFF-03, FR-OFF-04).
|
| Three rules the database keeps, because getting any of them wrong is the kind
| of error nobody notices until a citizen sees two mayors:
|
|  1. A seat is held by at most one person at a time. The btree_gist exclusion
|     constraint on (position_key, constituency_id, seat_index, period) makes an
|     overlapping second holding impossible, not merely unlikely.
|  2. A seat cannot be held and vacant over the same period.
|  3. A holding belongs to a constituency that actually has that seat: a ward
|     chair sits in a ward, a mayor in a municipality, and seat_index 3 does not
|     exist where the catalogue allows two.
|
| person_id and party_id point at rows in the CENTRAL database and so cannot be
| foreign keys — PostgreSQL has no cross-database references (D-014). The write
| actions check them, the nightly consistency job re-checks them, and the read
| path hydrates names through CurrentSeatsQuery.
|
| v_current_seats generates the seats a constituency is supposed to have from
| the catalogue, then attaches what is known about each one. A seat with no
| evidence reports 'not_verified' rather than 'vacant': an empty row in our
| database is not a statement about the world (FR-SRC-02, project instructions §3).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_holdings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('person_id')->comment('central ref: persons.id (D-014)');
            $table->string('position_key', 40);
            $table->uuid('constituency_id');
            $table->smallInteger('seat_index')->default(1);
            $table->uuid('party_id')->nullable()->comment('central ref: parties.id, as recorded at election (D-014)');
            $table->boolean('is_independent')->default(false);
            $table->uuid('candidacy_id')->nullable()->comment('candidacies.id — backfilled in v0.6');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->string('term_label', 40)->nullable();
            $table->timestampsTz();

            $table->foreign('position_key')->references('key')->on('positions')->restrictOnDelete();
            $table->foreign('constituency_id')->references('id')->on('admin_units')->restrictOnDelete();

            $table->index('person_id');
            $table->index('party_id');
            $table->index(['constituency_id', 'position_key', 'seat_index']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE office_holdings
                ADD CONSTRAINT office_holdings_seat_index_range
                    CHECK (seat_index BETWEEN 1 AND 99),
                ADD CONSTRAINT office_holdings_independent_has_no_party
                    CHECK (NOT (is_independent AND party_id IS NOT NULL)),
                ADD CONSTRAINT office_holdings_end_is_paired
                    CHECK ((end_date IS NULL) = (end_reason IS NULL)),
                ADD CONSTRAINT office_holdings_end_reason_check
                    CHECK (end_reason IS NULL OR end_reason IN
                        ('term_end', 'resignation', 'death', 'removal', 'suspension', 'other')),
                ADD CONSTRAINT office_holdings_period_order
                    CHECK (end_date IS NULL OR end_date > start_date)
            SQL);

        // One holder per seat per period. daterange '[)' makes an end date and
        // the successor's start date on the same day legal, which is how a
        // handover is actually recorded.
        DB::statement(<<<'SQL'
            ALTER TABLE office_holdings
                ADD CONSTRAINT office_holdings_no_seat_overlap
                    EXCLUDE USING gist (
                        (position_key::text) WITH =,
                        constituency_id WITH =,
                        seat_index WITH =,
                        daterange(start_date, end_date, '[)') WITH &&
                    )
            SQL);

        Schema::create('vacancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('position_key', 40);
            $table->uuid('constituency_id');
            $table->smallInteger('seat_index')->default(1);
            $table->date('vacant_from');
            $table->date('vacant_to')->nullable();
            $table->string('reason', 20)->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->foreign('position_key')->references('key')->on('positions')->restrictOnDelete();
            $table->foreign('constituency_id')->references('id')->on('admin_units')->restrictOnDelete();

            $table->index(['constituency_id', 'position_key', 'seat_index']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE vacancies
                ADD CONSTRAINT vacancies_seat_index_range
                    CHECK (seat_index BETWEEN 1 AND 99),
                ADD CONSTRAINT vacancies_reason_check
                    CHECK (reason IS NULL OR reason IN
                        ('never_filled', 'no_candidate', 'resignation', 'death', 'removal', 'suspension', 'other')),
                ADD CONSTRAINT vacancies_period_order
                    CHECK (vacant_to IS NULL OR vacant_to > vacant_from),
                ADD CONSTRAINT vacancies_no_seat_overlap
                    EXCLUDE USING gist (
                        (position_key::text) WITH =,
                        constituency_id WITH =,
                        seat_index WITH =,
                        daterange(vacant_from, vacant_to, '[)') WITH &&
                    )
            SQL);

        /*
         * A holding must fit the catalogue: right level of constituency, a
         * local level type the position applies to, and a seat index within the
         * seat count for that type. The same check guards vacancies, so a
         * vacancy cannot be declared for a seat that never existed — which is
         * how "123 units had no Dalit woman candidate" (docs/02 §4.2) stays
         * distinguishable from "that unit has no such seat".
         */
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION seat_reference_is_valid(
                p_position_key text,
                p_constituency_id uuid,
                p_seat_index smallint
            ) RETURNS void
            LANGUAGE plpgsql AS $$
            DECLARE
                pos positions%ROWTYPE;
                unit admin_units%ROWTYPE;
                unit_local_level_type text;
                seat_count int;
            BEGIN
                SELECT * INTO pos FROM positions WHERE key = p_position_key;
                IF NOT FOUND THEN
                    RETURN; -- the foreign key reports the unknown position
                END IF;

                SELECT * INTO unit FROM admin_units WHERE id = p_constituency_id;
                IF NOT FOUND THEN
                    RETURN; -- the foreign key reports the unknown constituency
                END IF;

                IF unit.level <> pos.constituency_level THEN
                    RAISE EXCEPTION 'seat %: a % is elected by a %, not by a %',
                        pos.key, pos.key, pos.constituency_level, unit.level
                        USING ERRCODE = 'check_violation';
                END IF;

                IF unit.level = 'ward' THEN
                    SELECT local_level_type INTO unit_local_level_type
                      FROM admin_units WHERE id = unit.parent_id;
                ELSE
                    unit_local_level_type := unit.local_level_type;
                END IF;

                IF unit_local_level_type IS NULL
                   OR NOT (unit_local_level_type = ANY (pos.applies_to_local_level_types)) THEN
                    RAISE EXCEPTION 'seat %: the position does not exist in a %',
                        pos.key, coalesce(unit_local_level_type, 'local level of unknown type')
                        USING ERRCODE = 'check_violation';
                END IF;

                seat_count := coalesce((pos.seats_per_constituency ->> unit_local_level_type)::int, 0);

                IF p_seat_index < 1 OR p_seat_index > seat_count THEN
                    RAISE EXCEPTION 'seat %: seat_index % is outside the % seat(s) a % has',
                        pos.key, p_seat_index, seat_count, unit_local_level_type
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$;

            CREATE FUNCTION office_holdings_validate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM seat_reference_is_valid(NEW.position_key, NEW.constituency_id, NEW.seat_index);

                IF EXISTS (
                    SELECT 1 FROM vacancies v
                    WHERE v.position_key = NEW.position_key
                      AND v.constituency_id = NEW.constituency_id
                      AND v.seat_index = NEW.seat_index
                      AND daterange(v.vacant_from, v.vacant_to, '[)')
                          && daterange(NEW.start_date, NEW.end_date, '[)')
                ) THEN
                    RAISE EXCEPTION 'office_holdings: the seat is recorded as vacant over part of that period'
                        USING ERRCODE = 'exclusion_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE FUNCTION vacancies_validate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM seat_reference_is_valid(NEW.position_key, NEW.constituency_id, NEW.seat_index);

                IF EXISTS (
                    SELECT 1 FROM office_holdings h
                    WHERE h.position_key = NEW.position_key
                      AND h.constituency_id = NEW.constituency_id
                      AND h.seat_index = NEW.seat_index
                      AND daterange(h.start_date, h.end_date, '[)')
                          && daterange(NEW.vacant_from, NEW.vacant_to, '[)')
                ) THEN
                    RAISE EXCEPTION 'vacancies: the seat is recorded as held over part of that period'
                        USING ERRCODE = 'exclusion_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER office_holdings_validity
                BEFORE INSERT OR UPDATE OF position_key, constituency_id, seat_index, start_date, end_date
                ON office_holdings
                FOR EACH ROW EXECUTE FUNCTION office_holdings_validate();

            CREATE TRIGGER vacancies_validity
                BEFORE INSERT OR UPDATE OF position_key, constituency_id, seat_index, vacant_from, vacant_to
                ON vacancies
                FOR EACH ROW EXECUTE FUNCTION vacancies_validate();
            SQL);

        /*
         * v_current_seats — the seats this local level is supposed to have,
         * with what is known about each. Directly elected positions only;
         * executive members chosen by the assembly are office holders but not
         * seats a voter fills (docs/02 §4.5).
         */
        DB::statement(<<<'SQL'
            CREATE VIEW v_current_seats AS
            WITH constituencies AS (
                SELECT u.id,
                       u.level                                               AS constituency_level,
                       u.ward_number,
                       coalesce(u.local_level_type, parent.local_level_type) AS local_level_type,
                       coalesce(parent.id, u.id)                             AS local_level_id
                FROM admin_units u
                LEFT JOIN admin_units parent ON parent.id = u.parent_id
                WHERE u.level IN ('local_level', 'ward')
                  AND u.valid_to IS NULL
                  AND u.is_published
            ),
            expected_seats AS (
                SELECT c.id                  AS constituency_id,
                       c.constituency_level,
                       c.local_level_id,
                       c.local_level_type,
                       c.ward_number,
                       p.key                 AS position_key,
                       p.title_ne,
                       p.title_en,
                       p.body,
                       p.seat_category,
                       p.ballot_order,
                       seat.n::smallint      AS seat_index
                FROM constituencies c
                JOIN positions p
                  ON p.constituency_level = c.constituency_level
                 AND p.election_method = 'direct'
                 AND p.valid_to IS NULL
                 AND c.local_level_type = ANY (p.applies_to_local_level_types)
                CROSS JOIN LATERAL generate_series(
                    1,
                    coalesce((p.seats_per_constituency ->> c.local_level_type)::int, 0)
                ) AS seat(n)
            )
            SELECT e.constituency_id,
                   e.constituency_level,
                   e.local_level_id,
                   e.local_level_type,
                   e.ward_number,
                   e.position_key,
                   e.title_ne,
                   e.title_en,
                   e.body,
                   e.seat_category,
                   e.ballot_order,
                   e.seat_index,
                   h.id            AS office_holding_id,
                   h.person_id,
                   h.party_id,
                   h.is_independent,
                   h.start_date,
                   h.term_label,
                   v.id            AS vacancy_id,
                   v.vacant_from,
                   v.reason        AS vacancy_reason,
                   CASE
                       WHEN h.id IS NOT NULL AND h.is_verified THEN 'held'
                       WHEN v.id IS NOT NULL AND v.is_verified THEN 'vacant'
                       ELSE 'not_verified'
                   END             AS seat_state
            FROM expected_seats e
            LEFT JOIN LATERAL (
                SELECT oh.id, oh.person_id, oh.party_id, oh.is_independent,
                       oh.start_date, oh.term_label,
                       EXISTS (
                           SELECT 1 FROM source_links sl
                           WHERE sl.subject_type = 'office_holding'
                             AND sl.subject_id = oh.id
                             AND sl.verification_status = 'verified'
                       ) AS is_verified
                FROM office_holdings oh
                WHERE oh.position_key = e.position_key
                  AND oh.constituency_id = e.constituency_id
                  AND oh.seat_index = e.seat_index
                  AND oh.start_date <= CURRENT_DATE
                  AND (oh.end_date IS NULL OR oh.end_date > CURRENT_DATE)
                ORDER BY oh.start_date DESC
                LIMIT 1
            ) h ON true
            LEFT JOIN LATERAL (
                SELECT vac.id, vac.vacant_from, vac.reason,
                       EXISTS (
                           SELECT 1 FROM source_links sl
                           WHERE sl.subject_type = 'vacancy'
                             AND sl.subject_id = vac.id
                             AND sl.verification_status = 'verified'
                       ) AS is_verified
                FROM vacancies vac
                WHERE vac.position_key = e.position_key
                  AND vac.constituency_id = e.constituency_id
                  AND vac.seat_index = e.seat_index
                  AND vac.vacant_from <= CURRENT_DATE
                  AND (vac.vacant_to IS NULL OR vac.vacant_to > CURRENT_DATE)
                ORDER BY vac.vacant_from DESC
                LIMIT 1
            ) v ON true
            SQL);

        $this->grantRuntimeRoles();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_current_seats');
        Schema::dropIfExists('vacancies');
        Schema::dropIfExists('office_holdings');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS office_holdings_validate() CASCADE;
            DROP FUNCTION IF EXISTS vacancies_validate() CASCADE;
            DROP FUNCTION IF EXISTS seat_reference_is_valid(text, uuid, smallint) CASCADE;
            SQL);
    }

    /**
     * Default privileges on the template database already cover tables created
     * by hw_owner, but a view is easy to miss and a missing grant only shows up
     * on the first production request. Granting again is free and idempotent.
     */
    private function grantRuntimeRoles(): void
    {
        $app = $this->role('tenancy.roles.app');
        $backup = $this->role('tenancy.roles.backup');

        if ($app !== null) {
            DB::unprepared(<<<SQL
                DO \$\$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$app}') THEN
                        EXECUTE format(
                            'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE office_holdings, vacancies, ward_offices TO %I',
                            '{$app}');
                        EXECUTE format(
                            'GRANT SELECT ON TABLE positions, admin_units, v_current_seats TO %I',
                            '{$app}');
                    END IF;
                END
                \$\$;
                SQL);
        }

        if ($backup !== null) {
            DB::unprepared(<<<SQL
                DO \$\$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$backup}') THEN
                        EXECUTE format(
                            'GRANT SELECT ON TABLE office_holdings, vacancies, ward_offices, positions, admin_units, v_current_seats TO %I',
                            '{$backup}');
                    END IF;
                END
                \$\$;
                SQL);
        }
    }

    /**
     * Role names reach SQL as literals inside a DO block, which cannot take
     * bindings. Anything that is not a plain PostgreSQL role name is refused
     * rather than escaped.
     */
    private function role(string $configKey): ?string
    {
        $name = trim((string) config($configKey, ''));

        if ($name === '') {
            return null;
        }

        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new InvalidArgumentException("config({$configKey}) is not a valid PostgreSQL role name: {$name}");
        }

        return $name;
    }
};
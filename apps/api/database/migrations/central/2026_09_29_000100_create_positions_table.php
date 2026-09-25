<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — the catalogue of local-level positions (docs/05 §5.1, docs/02 §3–4,
| R7, R8, FR-OFF-01).
|
| This is configuration, not election data. It describes which seats exist in a
| local level of a given type, how many of each, whether citizens elect them
| directly, and in what order they appear on a ballot. Nothing here names a
| municipality, a person or a party.
|
| valid_from / valid_to exist because the positions themselves can change: the
| pending Election Management Bill could alter them before the next local
| election (docs/02 §3.3). Retiring a position closes its row; holdings that
| already reference it keep resolving, and v_current_seats stops generating the
| seat. The key stays the primary key, so a materially redefined position takes
| a NEW key rather than a second row — one key, one meaning, forever.
|
| The catalogue is replicated read-only into every tenant database by
| SyncTenantReferenceData; office_holdings and v_current_seats read the replica.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table): void {
            $table->string('key', 40)->primary();
            $table->string('title_ne', 120);
            $table->string('title_en', 120);
            $table->string('body', 30);
            $table->string('constituency_level', 20);
            $table->string('seat_category', 20);
            $table->string('election_method', 10);
            $table->string('appointment_type', 20);
            $table->jsonb('seats_per_constituency');
            $table->smallInteger('ballot_order')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampsTz();

            $table->index(['constituency_level', 'ballot_order']);
        });

        // text[] has no Blueprint helper.
        DB::statement("ALTER TABLE positions ADD COLUMN applies_to_local_level_types text[] NOT NULL DEFAULT '{}'");

        DB::statement(<<<'SQL'
            ALTER TABLE positions
                ADD CONSTRAINT positions_key_format
                    CHECK (key ~ '^[a-z][a-z0-9_]*$'),
                ADD CONSTRAINT positions_body_check
                    CHECK (body IN ('executive', 'assembly', 'ward_committee', 'judicial_committee')),
                ADD CONSTRAINT positions_constituency_level_check
                    CHECK (constituency_level IN ('local_level', 'ward')),
                ADD CONSTRAINT positions_seat_category_check
                    CHECK (seat_category IN ('open', 'woman', 'dalit_woman', 'dalit_minority')),
                ADD CONSTRAINT positions_election_method_check
                    CHECK (election_method IN ('direct', 'indirect')),
                ADD CONSTRAINT positions_appointment_type_check
                    CHECK (appointment_type IN ('elected_direct', 'elected_indirect')),
                ADD CONSTRAINT positions_method_matches_appointment
                    CHECK ((election_method = 'direct') = (appointment_type = 'elected_direct')),
                ADD CONSTRAINT positions_local_level_types_known
                    CHECK (cardinality(applies_to_local_level_types) > 0
                       AND applies_to_local_level_types <@ ARRAY[
                           'metropolitan_city', 'sub_metropolitan_city', 'municipality', 'rural_municipality'
                       ]::text[]),
                ADD CONSTRAINT positions_seats_is_object
                    CHECK (jsonb_typeof(seats_per_constituency) = 'object'),
                ADD CONSTRAINT positions_ballot_order_range
                    CHECK (ballot_order IS NULL OR ballot_order BETWEEN 1 AND 99),
                ADD CONSTRAINT positions_validity_range
                    CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)
            SQL);

        /*
         * seats_per_constituency must carry exactly one positive seat count for
         * each local level type the position applies to. A CHECK cannot walk a
         * jsonb object against an array, so a trigger does it. Without this a
         * typo in a key would silently produce a position with no seats, and
         * v_current_seats would quietly stop generating them.
         */
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION positions_validate_seat_counts() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                declared text[];
                local_level_type text;
                seats jsonb;
            BEGIN
                SELECT coalesce(array_agg(k ORDER BY k), '{}')
                  INTO declared
                  FROM jsonb_object_keys(NEW.seats_per_constituency) AS k;

                IF NOT (declared @> NEW.applies_to_local_level_types
                        AND declared <@ NEW.applies_to_local_level_types) THEN
                    RAISE EXCEPTION 'positions %: seats_per_constituency keys % do not match applies_to_local_level_types %',
                        NEW.key, declared, NEW.applies_to_local_level_types
                        USING ERRCODE = 'check_violation';
                END IF;

                FOREACH local_level_type IN ARRAY NEW.applies_to_local_level_types LOOP
                    seats := NEW.seats_per_constituency -> local_level_type;

                    IF jsonb_typeof(seats) <> 'number' OR (seats::text)::numeric <> trunc((seats::text)::numeric)
                       OR (seats::text)::int < 1 OR (seats::text)::int > 99 THEN
                        RAISE EXCEPTION 'positions %: seat count for % must be a whole number between 1 and 99, got %',
                            NEW.key, local_level_type, coalesce(seats::text, 'null')
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER positions_seat_counts
                BEFORE INSERT OR UPDATE OF seats_per_constituency, applies_to_local_level_types ON positions
                FOR EACH ROW EXECUTE FUNCTION positions_validate_seat_counts();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
        DB::unprepared('DROP FUNCTION IF EXISTS positions_validate_seat_counts()');
    }
};

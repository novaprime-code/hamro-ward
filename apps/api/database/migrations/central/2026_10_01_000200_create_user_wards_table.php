<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| CENTRAL — the wards a citizen has saved (docs/12 §12.1, D-011, D-012).
|
| This is the whole of what the platform knows about where a person lives. Not
| an address: a ward. Precise enough to route a report and to offer the right
| wards first, and no more precise than that (see the users migration).
|
| Four rules the database keeps, rather than the application remembering to:
|
|  1. A saved row points at a WARD. A municipality or a district is not a place
|     you live in for this purpose, and a foreign key to admin_units cannot say
|     which level it wanted.
|  2. At most one primary ward per citizen, by partial unique index.
|  3. At most five saved wards, and at most three added in any 30 days (D-012
|     §12.4 rule 3). docs/12 assigns these to the action layer; they are here as
|     well, because a cap that lives only in one action is a cap that the next
|     code path — an import, an admin tool, a fixture — does not have.
|  4. A ward may be saved before its municipality is onboarded (§12.2), so
|     there is deliberately NO published requirement. The UI says "Hamro Ward
|     isn't open here yet" and the saved ward starts working on its own when
|     the tenant goes live.
|
| `created_at` is load-bearing, not bookkeeping: under `saved_wards_only` it is
| what the reporting cooldown is measured from, which is what stops someone
| adding a ward in order to post into it (§12.4 rule 1).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_wards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * restrictOnDelete, not cascade. An administrative unit is never
             * deleted in normal operation — a restructure closes it and opens a
             * successor (docs/05 §3) — so a DELETE here means something has
             * gone wrong, and taking citizens' saved wards with it would turn
             * one mistake into a second, silent one.
             */
            $table->foreignUuid('ward_id')->constrained('admin_units')->restrictOnDelete();

            $table->string('relationship', 20);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index('ward_id');
            $table->unique(['user_id', 'ward_id', 'relationship']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE user_wards
                ADD CONSTRAINT user_wards_relationship_check
                    CHECK (relationship IN
                        ('permanent_address', 'temporary_address', 'workplace', 'other'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX user_wards_one_primary
                ON user_wards (user_id) WHERE is_primary
            SQL);

        /*
         * The caps and the level rule.
         *
         * Counting rows in a BEFORE INSERT trigger is not serialisable: two
         * concurrent inserts can each see four rows and each proceed, leaving
         * six. At this scale — a person tapping "save" on a phone — that race
         * needs two requests in the same millisecond from one account, and the
         * cost of losing it is a citizen with one ward too many. A stricter fix
         * (SELECT ... FOR UPDATE on the user row, or a counter column) is
         * available if it ever matters; it is not worth the contention now.
         * What the trigger does buy is that no code path can skip the rule.
         */
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION user_wards_validate() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                unit admin_units%ROWTYPE;
                saved_count int;
                recent_count int;
            BEGIN
                SELECT * INTO unit FROM admin_units WHERE id = NEW.ward_id;

                IF NOT FOUND THEN
                    RETURN NEW; -- the foreign key reports the unknown ward
                END IF;

                IF unit.level <> 'ward' THEN
                    RAISE EXCEPTION 'user_wards: a saved ward must be a ward, not a %', unit.level
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Only on insert: a ward closed by a later restructure keeps the
                -- rows already saved against it, because removing them would
                -- quietly change what a citizen told us about themselves.
                IF TG_OP = 'INSERT' AND unit.valid_to IS NOT NULL THEN
                    RAISE EXCEPTION 'user_wards: ward % is closed and cannot be saved', unit.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    SELECT count(*) INTO saved_count
                      FROM user_wards WHERE user_id = NEW.user_id;

                    IF saved_count >= 5 THEN
                        RAISE EXCEPTION 'user_wards: at most 5 saved wards per person'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT count(*) INTO recent_count
                      FROM user_wards
                     WHERE user_id = NEW.user_id
                       AND created_at > now() - interval '30 days';

                    IF recent_count >= 3 THEN
                        RAISE EXCEPTION 'user_wards: at most 3 wards added per 30 days'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER user_wards_validity
                BEFORE INSERT OR UPDATE OF ward_id ON user_wards
                FOR EACH ROW EXECUTE FUNCTION user_wards_validate();
            SQL);

        $this->grantRuntimeRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_wards');
        DB::unprepared('DROP FUNCTION IF EXISTS user_wards_validate() CASCADE');
    }

    private function grantRuntimeRoles(): void
    {
        foreach (['tenancy.roles.app' => 'SELECT, INSERT, UPDATE, DELETE', 'tenancy.roles.backup' => 'SELECT'] as $key => $grant) {
            $role = trim((string) config($key, ''));

            if ($role === '') {
                continue;
            }

            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
                throw new InvalidArgumentException("config({$key}) is not a valid PostgreSQL role name: {$role}");
            }

            DB::unprepared(<<<SQL
                DO \$\$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                        EXECUTE format('GRANT {$grant} ON TABLE user_wards TO %I', '{$role}');
                    END IF;
                END
                \$\$;
                SQL);
        }
    }
};

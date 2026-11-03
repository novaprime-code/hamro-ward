<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| TENANT — photographs attached to reports (HW-E12-F01; docs/06 §7).
|
| In the municipality's own database, like the reports they belong to: a
| tenant export carries its photographs, and a tenant's database never holds
| another's. The object keys say the same thing a second way, every one of them
| beginning `tenants/{tenant_key}/`, so a misrouted write is visible in the
| bucket and not only in the schema.
|
| WHAT IS DELIBERATELY NOT HERE: the original file, once processing has
| finished, and anything the camera wrote. The original is deleted and the
| variants are re-encoded from pixels, so EXIF, GPS and XMP do not survive into
| storage at all (FR-MED-02) — there is no column for them because there is
| nothing left to put in one.
|
| Nor the uploader's name or address. reporter_user_id on the issue is the only
| link to a person, and it is nulled on resolution or account deletion
| (NFR-PRV-03); a photograph that outlived that link must not reintroduce it.
|
| visibility is the privacy rule in the schema (FR-MED-03): private until the
| report is approved, which nothing can do until moderation exists (HW-E14).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('status', 20)->default('uploaded');
            $table->string('visibility', 10)->default('private');

            // What arrived, as the server measured it — never as the client
            // described it.
            $table->string('mime', 40);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');

            /*
             * Where the bytes are. Always under this tenant's prefix, which a
             * check constraint below refuses to let drift.
             */
            $table->string('storage_prefix', 200);
            $table->string('original_key', 255)->nullable();

            // {"sm": {"key": …, "width": …, "height": …, "bytes": …}, "lg": …}
            $table->jsonb('variants')->default(DB::raw("'{}'::jsonb"));

            /*
             * The uploader, so a half-finished upload can be cleaned up and an
             * abusive one traced while it is still unattached. No foreign key:
             * accounts are central and PostgreSQL cannot reference across
             * databases.
             */
            $table->uuid('uploaded_by_user_id')->nullable();

            // Why processing failed, in words a moderator could act on.
            $table->text('error')->nullable();

            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index('uploaded_by_user_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE media
                ADD CONSTRAINT media_status_check
                    CHECK (status IN ('uploaded', 'processing', 'ready', 'failed')),
                ADD CONSTRAINT media_visibility_check
                    CHECK (visibility IN ('private', 'public')),
                ADD CONSTRAINT media_prefix_shape
                    CHECK (storage_prefix ~ '^tenants/[a-z0-9_]+/media/[0-9a-f-]{36}$'),
                ADD CONSTRAINT media_dimensions_positive
                    CHECK (width > 0 AND height > 0 AND byte_size > 0),
                /*
                 * A ready photograph has variants and no original; a failed one
                 * says why. Both are rules the application already keeps, written
                 * down here so that a future code path cannot quietly stop
                 * keeping them — above all the one that deletes the original.
                 */
                ADD CONSTRAINT media_ready_has_variants
                    CHECK (status <> 'ready' OR (variants <> '{}'::jsonb AND original_key IS NULL)),
                ADD CONSTRAINT media_failed_has_reason
                    CHECK (status <> 'failed' OR error IS NOT NULL)
        SQL);

        $this->grantRuntimeRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }

    /**
     * The application writes and updates media rows; the backup role only
     * reads, as everywhere else.
     */
    private function grantRuntimeRoles(): void
    {
        $grants = [
            'tenancy.roles.app' => 'SELECT, INSERT, UPDATE, DELETE',
            'tenancy.roles.backup' => 'SELECT',
        ];

        foreach ($grants as $key => $grant) {
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
                        EXECUTE format('REVOKE ALL ON TABLE media FROM %I', '{$role}');
                        EXECUTE format('GRANT {$grant} ON TABLE media TO %I', '{$role}');
                    END IF;
                END
                \$\$;
                SQL);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Found by running the suite against PostgreSQL (docs/programme/R41_PERFORMANCE.md): pulse_entities.verification_status
 * was created as varchar(12), and the value the InfoDot link writes, "infodot_member", is 14 characters. SQLite does not
 * enforce a string length, PostgreSQL does, so on production linking a business page to an InfoDot company failed with
 * "value too long for type character varying(12)". Widened to 24; left widened on rollback (narrowing could truncate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pulse_entities') || ! Schema::hasColumn('pulse_entities', 'verification_status')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE pulse_entities ALTER COLUMN verification_status TYPE VARCHAR(24)');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE pulse_entities MODIFY verification_status VARCHAR(24) NOT NULL DEFAULT 'unverified'");
        }
        // SQLite keeps whatever length was declared and enforces none.
    }

    public function down(): void
    {
        // Deliberately empty: shrinking the column could cut a stored value.
    }
};

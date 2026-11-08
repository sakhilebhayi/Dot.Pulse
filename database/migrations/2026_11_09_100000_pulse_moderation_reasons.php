<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a listing is held or removed, stored where the author and the moderator can both read it: a sentence for the
 * author (moderation_reason) and the rule flags behind it (moderation_flags: a list of {code, severity, text}).
 * Strings and json only, so it runs the same on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    private const TABLES = ['pulse_offers', 'pulse_roles', 'pulse_needs', 'pulse_entities'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'moderation_reason')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->text('moderation_reason')->nullable();
                    $t->json('moderation_flags')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'moderation_reason')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['moderation_reason', 'moderation_flags']));
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-41 D2: the indexes discovery, matching, search, the Floor and the digest actually query by, plus the one profile
 * column the age gate reads. Every statement is guarded so the migration is safe to run next to the other agents'
 * migrations (the age column is also added by the Lines migration "if absent").
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table => index name => columns */
    private array $indexes = [
        'pulse_signals' => [
            'pulse_signals_owner_status_index' => ['owner_id', 'status'],
            'pulse_signals_entity_status_index' => ['entity_id', 'status'],
            'pulse_signals_status_expires_index' => ['status', 'expires_at'],
            'pulse_signals_status_reach_index' => ['status', 'reach', 'published_at'],
        ],
        'pulse_matches' => [
            'pulse_matches_signal_index' => ['signal_id'],
            'pulse_matches_user_seen_index' => ['user_id', 'seen_at'],
        ],
        'pulse_saves' => [
            'pulse_saves_user_type_index' => ['user_id', 'saveable_type', 'created_at'],
        ],
        'pulse_blocks' => [
            'pulse_blocks_blocked_index' => ['blocked_id'],
        ],
        'pulse_entity_followers' => [
            'pulse_entity_followers_user_index' => ['user_id'],
        ],
        'pulse_notification_prefs' => [
            'pulse_notification_prefs_digest_index' => ['digest', 'frequency'],
        ],
        'pulse_places' => [
            'pulse_places_hierarchy_index' => ['country_code', 'region', 'city'],
            'pulse_places_name_index' => ['name'],
        ],
        'pulse_needs' => [
            'pulse_needs_user_status_index' => ['user_id', 'status'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }

        if (Schema::hasTable('pulse_profiles') && ! Schema::hasColumn('pulse_profiles', 'age_confirmed_at')) {
            Schema::table('pulse_profiles', fn (Blueprint $t) => $t->timestamp('age_confirmed_at')->nullable());
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
        // age_confirmed_at is deliberately left in place: another migration may own it by now.
    }
};

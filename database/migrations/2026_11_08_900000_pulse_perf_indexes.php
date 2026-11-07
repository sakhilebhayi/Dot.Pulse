<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * R-41 performance pass: the indexes the hot queries were missing, found by reading EXPLAIN on SQLite and PostgreSQL
 * against 20 000 people, 34 000 signals, 60 000 lines and 460 000 messages (docs/programme/R41_PERFORMANCE.md).
 *
 *  - pulse_profiles.user_id and pulse_notification_prefs.user_id were declared "->constrained()->cascadeOnDelete()->unique()",
 *    and the trailing unique() is silently dropped by Laravel, so neither column had any index: every profile lookup
 *    (matching, the Floor, search, the digest) was a sequential scan, and nothing stopped a second profile row.
 *    The unique index is created when the data allows it; a database that already holds duplicates gets a plain
 *    index instead (and a log line), so the migration never fails on real data.
 *  - pulse_entity_members by user (who answers for which page): the inbox, the badge and the Floor ask this per request.
 *  - pulse_followers by the followed person, pulse_conversations by page and date (reply behaviour), by date alone
 *    (operator ranges), pulse_messages by line and id (latest message per line) and by author, pulse_matches by seen
 *    date (operator ranges), pulse_signals and pulse_profiles by update time (the match run reads what changed).
 *
 * Every statement is guarded so the migration is idempotent and safe to run next to the other R-41 migrations.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table => index name => columns */
    private array $indexes = [
        'pulse_profiles' => [
            'pulse_profiles_place_user_index' => ['place_id', 'user_id'],
            'pulse_profiles_updated_at_index' => ['updated_at'],
        ],
        'pulse_entity_members' => [
            'pulse_entity_members_user_role_index' => ['user_id', 'role', 'entity_id'],
        ],
        'pulse_followers' => [
            'pulse_followers_following_index' => ['following_id', 'follower_id'],
        ],
        'pulse_conversations' => [
            'pulse_conversations_entity_created_index' => ['entity_id', 'created_at'],
        ],
        'pulse_messages' => [
            'pulse_messages_conversation_id_index' => ['pulse_conversation_id', 'id'],
            'pulse_messages_user_index' => ['user_id'],
        ],
        'pulse_matches' => [
            'pulse_matches_seen_at_index' => ['seen_at'],
        ],
        'pulse_signals' => [
            'pulse_signals_updated_at_index' => ['updated_at'],
        ],
    ];

    /** @var array<string, string> table => the column that was meant to be unique */
    private array $uniques = [
        'pulse_profiles' => 'pulse_profiles_user_id_unique',
        'pulse_notification_prefs' => 'pulse_notification_prefs_user_id_unique',
    ];

    public function up(): void
    {
        foreach ($this->uniques as $table => $name) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name) || Schema::hasIndex($table, ['user_id'], 'unique')) {
                continue;
            }

            // Looked for first, not caught: on PostgreSQL a failed statement would abort the migration's transaction.
            $duplicated = DB::table($table)->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->limit(1)->exists();

            if (! $duplicated) {
                Schema::table($table, fn (Blueprint $t) => $t->unique('user_id', $name));

                continue;
            }

            // Duplicate rows already exist: keep the lookup fast, leave the clean-up to a person.
            Log::warning("{$table}.user_id holds duplicates, so a plain index was created instead of a unique one.");

            if (! Schema::hasIndex($table, ['user_id'])) {
                Schema::table($table, fn (Blueprint $t) => $t->index('user_id', "{$table}_user_id_index"));
            }
        }

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

        // Nearly every line has no initiating page, so the plain index on that column is almost all NULL and its
        // statistics say "60 000 rows per page". SQLite believes that and abandons the per-column indexes for the
        // inbox's OR, scanning every open line instead (measured: 18 ms became 54 ms). The partial index holds only
        // real values, so the statistics are honest and the OR is answered from four small index ranges again.
        if (Schema::hasTable('pulse_conversations') && in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE INDEX IF NOT EXISTS '.self::PARTIAL.' ON pulse_conversations (initiator_entity_id) WHERE initiator_entity_id IS NOT NULL');
        }

        // The operator's date ranges on lines. PostgreSQL uses it to read a 7 day window without touching the other
        // 83 days; SQLite already answers from (status, created_at) with a skip scan and, given this one as well,
        // chooses it for a 30 day window that is a third of the table and runs 6 times slower (measured).
        if (Schema::hasTable('pulse_conversations') && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS '.self::CREATED.' ON pulse_conversations (created_at)');
        }
    }

    private const PARTIAL = 'pulse_conversations_initiator_entity_partial';

    private const CREATED = 'pulse_conversations_created_at_index';

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::PARTIAL);
            DB::statement('DROP INDEX IF EXISTS '.self::CREATED);
        }

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

        foreach ($this->uniques as $table => $name) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ([$name, "{$table}_user_id_index"] as $candidate) {
                if (Schema::hasIndex($table, $candidate)) {
                    Schema::table($table, fn (Blueprint $t) => $candidate === $name ? $t->dropUnique($candidate) : $t->dropIndex($candidate));
                }
            }
        }
    }
};

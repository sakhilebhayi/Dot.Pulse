<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-41 (agent D3, "Lines"): what the contact, conversation, quote, outcome, review, block, notification, consent
 * and audit services need on top of the business schema (2026_11_08_100000).
 *
 *  - age gate: pulse_profiles.age_confirmed_at (guarded, another agent may add it too)
 *  - conversations: recipient, who is speaking for which page, the enquiry card and the context snapshot, how a line
 *    ended, per side read marks, quote groups and doors
 *  - quotes: ranges, earliest date, included and excluded
 *  - reviews: blind until both sides submit or 14 days pass, pseudonymous display, moderation state
 *  - knock budgets, open doors, blocks between a person and a page
 *  - notification quiet hours
 *  - the audit log is append only at the database level (SQLite and PostgreSQL): the only change a row may ever
 *    receive is the actor (and the ip hash) being nulled when a person is erased.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pulse_profiles', 'age_confirmed_at')) {
            Schema::table('pulse_profiles', function (Blueprint $table) {
                $table->timestamp('age_confirmed_at')->nullable(); // the person declared they are 18 or older
            });
        }

        Schema::create('pulse_doors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('pulse_entities')->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose', 160);
            $table->string('length', 4); // day, week
            $table->string('status', 8)->default('open'); // open, closed, lapsed
            $table->foreignId('signal_id')->nullable()->constrained('pulse_signals')->nullOnDelete();
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->unsignedSmallInteger('knock_cap')->default(10);
            $table->unsignedSmallInteger('knocks_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'closes_at']);
            $table->index(['owner_id', 'status']);
            $table->index(['entity_id', 'status']);
        });

        Schema::table('pulse_conversations', function (Blueprint $table) {
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete(); // the person contacted, when not a page
            $table->foreignId('initiator_entity_id')->nullable()->constrained('pulse_entities')->nullOnDelete(); // a page that started the line (talent invites)
            $table->foreignId('door_id')->nullable()->constrained('pulse_doors')->nullOnDelete();
            $table->string('quote_group', 36)->nullable(); // one quote request sent to up to three providers
            $table->json('card')->nullable(); // the enquiry card exactly as sent: what, when_or_qty, where, budget_band
            $table->json('context_snapshot')->nullable(); // what the line is about, copied at knock time
            $table->string('decline_reason', 24)->nullable();
            $table->string('closed_reason', 24)->nullable(); // withdrawn, closed, expired, blocked, spam, declined, not_chosen, outcome
            $table->timestamp('ignored_at')->nullable(); // the recipient let it lapse quietly; the sender is not told
            $table->timestamp('refilled_at')->nullable(); // the sender's knock budget was refilled for this reply
            $table->string('outcome_side', 10)->nullable(); // which side proposed the current outcome
            $table->timestamp('outcome_disputed_at')->nullable();
            $table->timestamp('initiator_read_at')->nullable();
            $table->timestamp('recipient_read_at')->nullable();
            $table->string('last_message_side', 10)->nullable();
            $table->index(['recipient_id', 'status']);
            $table->index(['initiator_id', 'status']);
            $table->index(['entity_id', 'status']);
            $table->index(['initiator_entity_id', 'status']);
            $table->index('quote_group');
            $table->index(['status', 'created_at']);
        });

        Schema::table('pulse_messages', function (Blueprint $table) {
            $table->string('side', 10)->nullable(); // initiator, recipient
            $table->timestamp('erased_at')->nullable();
        });

        Schema::table('pulse_quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_max_cents')->nullable(); // a range: amount_cents is the low end
            $table->date('earliest_date')->nullable();
            $table->text('included')->nullable();
            $table->text('excluded')->nullable();
            $table->index(['status', 'valid_until']);
            $table->index('pulse_conversation_id');
        });

        Schema::table('pulse_reviews', function (Blueprint $table) {
            $table->string('reviewer_side', 10)->nullable(); // initiator, recipient
            $table->string('outcome', 12)->nullable(); // the confirmed outcome this review stands on
            $table->boolean('pseudonymous')->default(false);
            $table->string('moderation', 10)->default('approved'); // approved, held, removed
            $table->timestamp('revealed_at')->nullable();
            $table->unique(['conversation_id', 'user_id'], 'pulse_reviews_one_per_side');
        });

        Schema::table('pulse_notification_prefs', function (Blueprint $table) {
            $table->string('quiet_start', 5)->nullable();
            $table->string('quiet_end', 5)->nullable();
        });

        Schema::create('pulse_knock_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('window_started_at');
            $table->unsignedSmallInteger('spent')->default(0);
            $table->unsignedSmallInteger('bonus')->default(0); // refills earned from replies this window
            $table->unsignedSmallInteger('penalty')->default(0); // shrinks from confirmed spam; decays one per clean window
            $table->timestamps();
        });

        Schema::create('pulse_entity_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('pulse_entities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('side', 6); // user: the person blocked the page. entity: the page blocked the person.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['entity_id', 'user_id', 'side']);
        });

        $this->guardAuditLog();
    }

    public function down(): void
    {
        $this->unguardAuditLog();

        Schema::dropIfExists('pulse_entity_blocks');
        Schema::dropIfExists('pulse_knock_budgets');

        Schema::table('pulse_notification_prefs', fn (Blueprint $t) => $t->dropColumn(['quiet_start', 'quiet_end']));

        Schema::table('pulse_reviews', function (Blueprint $table) {
            $table->dropUnique('pulse_reviews_one_per_side');
        });
        Schema::table('pulse_reviews', fn (Blueprint $t) => $t->dropColumn(['reviewer_side', 'outcome', 'pseudonymous', 'moderation', 'revealed_at']));

        Schema::table('pulse_quotes', function (Blueprint $table) {
            $table->dropIndex(['status', 'valid_until']);
            $table->dropIndex(['pulse_conversation_id']);
        });
        Schema::table('pulse_quotes', fn (Blueprint $t) => $t->dropColumn(['amount_max_cents', 'earliest_date', 'included', 'excluded']));

        Schema::table('pulse_messages', fn (Blueprint $t) => $t->dropColumn(['side', 'erased_at']));

        Schema::table('pulse_conversations', function (Blueprint $table) {
            $table->dropIndex(['recipient_id', 'status']);
            $table->dropIndex(['initiator_id', 'status']);
            $table->dropIndex(['entity_id', 'status']);
            $table->dropIndex(['initiator_entity_id', 'status']);
            $table->dropIndex(['quote_group']);
            $table->dropIndex(['status', 'created_at']);
        });
        Schema::table('pulse_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_id');
            $table->dropConstrainedForeignId('initiator_entity_id');
            $table->dropConstrainedForeignId('door_id');
        });
        Schema::table('pulse_conversations', fn (Blueprint $t) => $t->dropColumn([
            'quote_group', 'card', 'context_snapshot', 'decline_reason', 'closed_reason', 'ignored_at', 'refilled_at',
            'outcome_side', 'outcome_disputed_at', 'initiator_read_at', 'recipient_read_at', 'last_message_side',
        ]));

        Schema::dropIfExists('pulse_doors');
        // pulse_profiles.age_confirmed_at stays: it may belong to another migration and dropping it loses declarations.
    }

    private function guardAuditLog(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER pulse_audit_log_no_update BEFORE UPDATE ON pulse_audit_log
WHEN NOT (NEW.actor_id IS NULL AND NEW.id = OLD.id AND NEW.action IS OLD.action AND NEW.subject_type IS OLD.subject_type
          AND NEW.subject_id IS OLD.subject_id AND NEW.meta IS OLD.meta AND NEW.created_at IS OLD.created_at
          AND (NEW.ip_hash IS NULL OR NEW.ip_hash IS OLD.ip_hash))
BEGIN SELECT RAISE(ABORT, 'pulse_audit_log is append only'); END;
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER pulse_audit_log_no_delete BEFORE DELETE ON pulse_audit_log
BEGIN SELECT RAISE(ABORT, 'pulse_audit_log is append only'); END;
SQL);

            return;
        }

        if ($driver === 'pgsql') {
            try {
                // A savepoint, so a host that does not let this role create functions does not abort the migration.
                DB::transaction(function () {
                    DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pulse_audit_log_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'pulse_audit_log is append only';
    END IF;
    IF NEW.actor_id IS NULL AND NEW.id = OLD.id AND NEW.action IS NOT DISTINCT FROM OLD.action
       AND NEW.subject_type IS NOT DISTINCT FROM OLD.subject_type AND NEW.subject_id IS NOT DISTINCT FROM OLD.subject_id
       AND NEW.meta::text IS NOT DISTINCT FROM OLD.meta::text AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
       AND (NEW.ip_hash IS NULL OR NEW.ip_hash IS NOT DISTINCT FROM OLD.ip_hash) THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'pulse_audit_log is append only';
END;
$$ LANGUAGE plpgsql;
SQL);
                    DB::unprepared('CREATE TRIGGER pulse_audit_log_guard BEFORE UPDATE OR DELETE ON pulse_audit_log FOR EACH ROW EXECUTE FUNCTION pulse_audit_log_guard()');
                });
            } catch (Throwable $e) {
                report($e); // the model level guard still holds; the database guard is defence in depth
            }
        }
    }

    private function unguardAuditLog(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS pulse_audit_log_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS pulse_audit_log_no_delete');
        } elseif ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS pulse_audit_log_guard ON pulse_audit_log');
            DB::unprepared('DROP FUNCTION IF EXISTS pulse_audit_log_guard()');
        }
    }
};

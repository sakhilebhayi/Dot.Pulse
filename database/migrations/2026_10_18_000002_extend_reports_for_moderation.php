<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // pulse_reports shipped with no code behind it. It already has the
        // reporter, a polymorphic target (indexed by morphs()), a reason and a
        // status; this adds what the moderation queue needs.
        Schema::table('pulse_reports', function (Blueprint $table) {
            // Denormalised from the target so a community's moderators can be
            // scoped to their own reports with one indexed predicate.
            $table->foreignId('community_id')->nullable()->after('reportable_id')
                ->constrained('communities')->cascadeOnDelete();
            $table->foreignId('resolved_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
            $table->string('resolution', 24)->nullable()->after('resolved_at'); // removed, dismissed, reviewed

            // One report per person per target.
            $table->unique(['reporter_id', 'reportable_type', 'reportable_id'], 'pulse_reports_one_per_reporter_target');
            $table->index(['community_id', 'status'], 'pulse_reports_community_status_idx');
        });

        // Who did what to a target, newest first, without a table scan.
        Schema::table('pulse_moderation_logs', function (Blueprint $table) {
            $table->index(['target_type', 'target_id', 'created_at'], 'pulse_moderation_logs_target_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pulse_moderation_logs', function (Blueprint $table) {
            $table->dropIndex('pulse_moderation_logs_target_time_idx');
        });

        Schema::table('pulse_reports', function (Blueprint $table) {
            $table->dropIndex('pulse_reports_community_status_idx');
            $table->dropUnique('pulse_reports_one_per_reporter_target');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'resolution']);
            $table->dropConstrainedForeignId('community_id');
        });
    }
};

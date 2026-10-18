<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained('communities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending, approved, denied
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'user_id']);
            $table->index(['community_id', 'status']);
        });

        Schema::table('pulse_posts', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('ai_relevance_score');
            $table->timestamp('edited_at')->nullable()->after('published_at');
            $table->softDeletes();
        });

        Schema::table('pulse_comments', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('reactions_count');
            $table->softDeletes();
        });

        // Posts that were already live keep a sensible sort/audit time.
        DB::table('pulse_posts')
            ->where('status', 'published')
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('created_at')]);

        // Deleting a community used to null pulse_posts.community_id, which
        // would turn every post of a deleted PRIVATE community into a public,
        // community-less post. The posts go with the community instead.
        Schema::table('pulse_posts', function (Blueprint $table) {
            $table->dropForeign(['community_id']);
        });
        Schema::table('pulse_posts', function (Blueprint $table) {
            $table->foreign('community_id')->references('id')->on('communities')->cascadeOnDelete();
        });

        // A hard-deleted comment used to orphan its replies (parent_id set
        // null turns a reply into a top-level comment). Replies go with it;
        // soft deletes cascade in PulseComment::booted().
        Schema::table('pulse_comments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });
        Schema::table('pulse_comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('pulse_comments')->cascadeOnDelete();
        });

        // SQLite and Postgres do not index foreign keys on their own.
        Schema::table('communities', function (Blueprint $table) {
            $table->index('team_id');
        });
        Schema::table('pulse_posts', function (Blueprint $table) {
            $table->index('team_id');
            $table->index(['community_id', 'status', 'published_at'], 'pulse_posts_community_status_published_idx');
        });
        Schema::table('community_memberships', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('community_memberships', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
        Schema::table('pulse_posts', function (Blueprint $table) {
            $table->dropIndex('pulse_posts_community_status_published_idx');
            $table->dropIndex(['team_id']);
        });
        Schema::table('communities', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
        });

        Schema::table('pulse_comments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });
        Schema::table('pulse_comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('pulse_comments')->nullOnDelete();
        });

        Schema::table('pulse_posts', function (Blueprint $table) {
            $table->dropForeign(['community_id']);
        });
        Schema::table('pulse_posts', function (Blueprint $table) {
            $table->foreign('community_id')->references('id')->on('communities')->nullOnDelete();
        });

        Schema::table('pulse_comments', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('edited_at');
        });
        Schema::table('pulse_posts', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['published_at', 'edited_at']);
        });

        Schema::dropIfExists('community_join_requests');
    }
};

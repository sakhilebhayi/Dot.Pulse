<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ring check for outcomes and reviews. A confirmed outcome between two accounts that keep confirming each other's is
 * accepted but flagged `needs_review` until a moderator clears it; it does not count in public metrics or the ladder
 * meanwhile. Reviews on a flagged result are held (`held_for_ring`) and decided with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pulse_conversations', 'needs_review')) {
            Schema::table('pulse_conversations', function (Blueprint $table) {
                $table->boolean('needs_review')->default(false);
                $table->string('needs_review_reason', 24)->nullable();
                $table->timestamp('needs_review_at')->nullable();
                $table->timestamp('review_cleared_at')->nullable();
                $table->unsignedBigInteger('review_cleared_by')->nullable();
                $table->index('needs_review', 'pulse_conversations_needs_review_index');
            });
        }

        if (! Schema::hasColumn('pulse_reviews', 'held_for_ring')) {
            Schema::table('pulse_reviews', function (Blueprint $table) {
                $table->boolean('held_for_ring')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pulse_reviews', 'held_for_ring')) {
            Schema::table('pulse_reviews', fn (Blueprint $table) => $table->dropColumn('held_for_ring'));
        }

        if (Schema::hasColumn('pulse_conversations', 'needs_review')) {
            Schema::table('pulse_conversations', function (Blueprint $table) {
                $table->dropIndex('pulse_conversations_needs_review_index');
                $table->dropColumn(['needs_review', 'needs_review_reason', 'needs_review_at', 'review_cleared_at', 'review_cleared_by']);
            });
        }
    }
};

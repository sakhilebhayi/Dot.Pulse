<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A hiring signal lapses unless its page renews it. Existing signals get a full 60 days from this migration, so
 * nobody loses one the day this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pulse_entities', 'hiring_signal_until')) {
            Schema::table('pulse_entities', function (Blueprint $table) {
                $table->timestamp('hiring_signal_until')->nullable();
                $table->index(['hiring_signal', 'hiring_signal_until'], 'pulse_entities_hiring_until_index');
            });
        }

        DB::table('pulse_entities')
            ->where('hiring_signal', '!=', 'none')
            ->whereNull('hiring_signal_until')
            ->update(['hiring_signal_until' => now()->addDays(60)]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('pulse_entities', 'hiring_signal_until')) {
            Schema::table('pulse_entities', function (Blueprint $table) {
                $table->dropIndex('pulse_entities_hiring_until_index');
                $table->dropColumn('hiring_signal_until');
            });
        }
    }
};

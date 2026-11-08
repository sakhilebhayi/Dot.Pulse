<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * People whose Pulse profile is older than today were here before the welcome flow existed. They are marked as
 * onboarded so the flow does not stop them once. Their age declaration is NOT filled in for them: that stays a thing
 * the person says, never something inferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pulse_profiles')
            ->whereNull('onboarded_at')
            ->where('created_at', '<', now()->startOfDay())
            ->update(['onboarded_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Nothing to undo: an onboarded_at that was filled in here is indistinguishable from a real one.
    }
};

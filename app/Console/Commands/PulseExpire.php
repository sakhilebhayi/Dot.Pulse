<?php

namespace App\Console\Commands;

use App\Models\PulseRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PulseExpire extends Command
{
    protected $signature = 'pulse:expire';

    protected $description = 'Expire needs, open doors, hiring signals, roles and offers past their end date, and tidy matches for things that are gone';

    public function handle(): int
    {
        $now = now();

        // Needs, doors and hiring signals are signals with an end date.
        $signals = DB::table('pulse_signals')->where('status', 'live')->whereNotNull('expires_at')->where('expires_at', '<=', $now)
            ->update(['status' => 'expired', 'updated_at' => $now]);

        $needs = DB::table('pulse_needs')->where('status', 'open')->whereNotNull('expires_at')->where('expires_at', '<=', $now)
            ->update(['status' => 'closed', 'updated_at' => $now]);

        $roles = DB::table('pulse_roles')->where('status', 'live')->whereNotNull('closes_at')->where('closes_at', '<=', $now)->whereNull('deleted_at')
            ->update(['status' => 'closed', 'updated_at' => $now]);

        // An offer that ran out is paused, not deleted: its owner can publish it again.
        $offers = DB::table('pulse_offers')->where('status', 'live')->whereNotNull('expires_at')->where('expires_at', '<=', $now)->whereNull('deleted_at')
            ->update(['status' => 'paused', 'updated_at' => $now]);

        // Role signals follow their role's closing date even where nothing set the signal's own expiry.
        $roleSignals = DB::table('pulse_signals')->where('status', 'live')->whereIn('subject_type', ['role', PulseRole::class])
            ->whereIn('subject_id', fn ($q) => $q->select('id')->from('pulse_roles')->where('status', '!=', 'live'))
            ->update(['status' => 'expired', 'updated_at' => $now]);

        // Matches for things that have been gone a month, and very old unseen ones.
        $purged = DB::table('pulse_matches')
            ->whereIn('signal_id', fn ($q) => $q->select('id')->from('pulse_signals')->where('status', '!=', 'live')->where('updated_at', '<', $now->copy()->subDays(30)))
            ->delete()
            + DB::table('pulse_matches')->whereNull('dismissed_at')->where('computed_at', '<', $now->copy()->subDays(90))->delete();

        $this->info("Signals expired: {$signals}, role signals: {$roleSignals}, needs closed: {$needs}, roles closed: {$roles}, offers paused: {$offers}, matches purged: {$purged}");

        return self::SUCCESS;
    }
}

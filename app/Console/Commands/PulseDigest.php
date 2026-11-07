<?php

namespace App\Console\Commands;

use App\Models\PulseNotificationPref;
use App\Models\User;
use App\Services\Pulse\Discovery\Digest\DigestSender;
use Illuminate\Console\Command;

class PulseDigest extends Command
{
    protected $signature = 'pulse:digest {--user= : Send to one user id now, if they want a digest} {--dry : Say who would get one, send nothing}';

    protected $description = 'Send the Dot.Pulse digest to people whose digest hour has come in their timezone (only when there is something real)';

    public function handle(DigestSender $sender): int
    {
        if ($id = $this->option('user')) {
            $user = User::find($id);
            $sent = $user !== null && $sender->send($user);
            $this->info($sent ? 'Digest sent.' : 'Nothing sent.');

            return self::SUCCESS;
        }

        $sent = $due = 0;
        $dry = (bool) $this->option('dry');

        PulseNotificationPref::query()
            ->where('digest', true)
            ->whereIn('frequency', ['instant', 'daily', 'weekly'])
            ->orderBy('id')
            ->chunkById(200, function ($prefs) use ($sender, &$sent, &$due, $dry) {
                foreach ($prefs as $pref) {
                    if (! $sender->dueFor($pref, now())) {
                        continue;
                    }

                    $due++;

                    if ($dry) {
                        $this->line("Would send to user {$pref->user_id}");

                        continue;
                    }

                    $user = User::find($pref->user_id);

                    if ($user !== null && $sender->send($user)) {
                        $sent++;
                    }
                }
            });

        $this->info("Due: {$due}, sent: {$sent}");

        return self::SUCCESS;
    }
}

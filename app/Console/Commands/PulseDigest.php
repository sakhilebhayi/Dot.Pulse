<?php

namespace App\Console\Commands;

use App\Models\PulseNotificationPref;
use App\Models\User;
use App\Services\Pulse\Discovery\Digest\DigestBuilder;
use App\Services\Pulse\Discovery\Digest\DigestSender;
use Illuminate\Console\Command;

class PulseDigest extends Command
{
    protected $signature = 'pulse:digest {--user= : Send to one user id now, if they want a digest} {--dry : Say who would get one, send nothing}';

    protected $description = 'Send the Dot.Pulse digest to people whose digest hour has come in their timezone (only when there is something real)';

    public function handle(DigestSender $sender, DigestBuilder $builder): int
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
            ->chunkById(200, function ($prefs) use ($sender, $builder, &$sent, &$due, $dry) {
                $dueNow = $prefs->filter(fn ($pref) => $sender->dueFor($pref, now()))->values();

                if ($dueNow->isEmpty()) {
                    return;
                }

                // Who could have anything to say, for the whole chunk in one statement; the rest are skipped without a build.
                $since = $dueNow->mapWithKeys(fn ($p) => [$p->user_id => $p->last_digest_at ?? now()->subDays($p->frequency === 'weekly' ? 7 : 2)])->all();
                $worth = array_flip($builder->usersWithNews($dueNow->pluck('user_id')->map(fn ($v) => (int) $v)->all(), $since));
                $due += $dueNow->count();
                $users = $dry ? collect() : User::query()->whereIn('id', $dueNow->pluck('user_id'))->get()->keyBy('id');

                foreach ($dueNow as $pref) {
                    if ($dry) {
                        $this->line("Would send to user {$pref->user_id}");

                        continue;
                    }

                    $user = isset($worth[$pref->user_id]) ? $users->get($pref->user_id) : null;

                    if ($user !== null && $sender->send($user)) {
                        $sent++;
                    }
                }
            });

        $this->info("Due: {$due}, sent: {$sent}");

        return self::SUCCESS;
    }
}

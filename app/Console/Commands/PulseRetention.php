<?php

namespace App\Console\Commands;

use App\Services\Pulse\Lines\RetentionPurge;
use Illuminate\Console\Command;

class PulseRetention extends Command
{
    protected $signature = 'pulse:retention {--dry-run : Count what would be purged without changing anything}';

    protected $description = 'Purge what has outlived its purpose: old closed-line messages, dead requests, ended signals, read notifications, audit actors, finished invitations (numbers in config/pulse.php)';

    public function handle(RetentionPurge $purge): int
    {
        $done = $purge->run((bool) $this->option('dry-run'));

        foreach ($done as $what => $count) {
            $this->line(sprintf('%-24s %d', str_replace('_', ' ', $what), $count));
        }

        $this->info($this->option('dry-run') ? 'Dry run: nothing was changed.' : 'Retention purge done.');

        return self::SUCCESS;
    }
}

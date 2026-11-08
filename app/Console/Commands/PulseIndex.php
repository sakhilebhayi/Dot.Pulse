<?php

namespace App\Console\Commands;

use App\Services\Pulse\Discovery\PostSignalAdapter;
use Illuminate\Console\Command;

class PulseIndex extends Command
{
    protected $signature = 'pulse:index {--full : Re-index every post, not just the ones changed in the last two days}';

    protected $description = 'Rebuild the Dot.Pulse discovery index (signals) from offers, needs, roles, profiles, businesses and posts';

    public function handle(PostSignalAdapter $posts): int
    {
        $indexer = 'App\\Services\\Pulse\\Entities\\SignalIndexer';

        if (class_exists($indexer) && method_exists($indexer, 'rebuild')) {
            $this->info('Signals rebuilt: '.app($indexer)->rebuild());
        } else {
            $this->warn('SignalIndexer is not installed here; indexing posts only.');
        }

        // Posts are always synced here: it is idempotent, so it is safe next to an indexer that also does it.
        $n = $posts->syncAll($this->option('full') ? null : now()->subDays(2));
        $this->info("Posts synced: {$n}");

        return self::SUCCESS;
    }
}

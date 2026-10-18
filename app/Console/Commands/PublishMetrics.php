<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsClient;
use App\Services\Analytics\SnapshotPublisher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Queues today's platform-wide metric snapshot for Dot.Analytics. Scheduled daily (routes/console.php).
 * Harmless by design: nothing happens until the url, secret and company uuid are all set, and a
 * Dot.Analytics outage can only ever fail a queued job, never this app.
 */
class PublishMetrics extends Command
{
    protected $signature = 'pulse:publish-metrics {--dry-run : Print the payload that would be sent; send and queue nothing}';

    protected $description = 'Queue the daily aggregate metric snapshot for Dot.Analytics';

    public function handle(SnapshotPublisher $publisher, AnalyticsClient $client): int
    {
        try {
            if ($this->option('dry-run')) {
                $this->line((string) json_encode($publisher->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->line($client->enabled()
                    ? 'Dry run: nothing was sent or queued.'
                    : 'Dry run: nothing was sent or queued, and with the current settings it would not be sent (ANALYTICS_INGEST_URL, ANALYTICS_INGEST_SECRET and ANALYTICS_COMPANY_UUID must all be set).');

                return self::SUCCESS;
            }

            if (! $client->enabled()) {
                $this->line('Publishing to Dot.Analytics is not configured (ANALYTICS_INGEST_URL, ANALYTICS_INGEST_SECRET and ANALYTICS_COMPANY_UUID must all be set). Nothing sent.');

                return self::SUCCESS;
            }

            $payload = $publisher->publish();

            $this->line($payload === null
                ? 'The snapshot could not be queued (see the log). Nothing sent.'
                : "Queued the {$payload['platform']} snapshot for {$payload['taken_at']}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Could not compute the snapshot: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}

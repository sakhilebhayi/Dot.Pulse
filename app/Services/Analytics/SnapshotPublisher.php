<?php

namespace App\Services\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds today's snapshot and queues it. The only entry point the scheduled command uses.
 */
class SnapshotPublisher
{
    public function __construct(private MetricsCollector $collector, private AnalyticsClient $client) {}

    /**
     * The exact body that would be sent for the UTC day containing $asOf. taken_at is the START of that
     * UTC day, so every run on the same day names the same snapshot and Analytics treats the repeat as a replay.
     *
     * @return array<string, mixed>
     */
    public function payload(?CarbonInterface $asOf = null): array
    {
        $asOf = Carbon::instance($asOf ?? now())->utc();

        return [
            'platform' => (string) config('analytics.platform', 'pulse'),
            'company_uuid' => (string) config('analytics.company_uuid'),
            'taken_at' => $asOf->copy()->startOfDay()->format('Y-m-d\TH:i:s\Z'),
            'period' => 'day',
            'metrics' => $this->metrics($asOf),
        ];
    }

    /**
     * The three catalogue metrics, always, in their fixed order, then (unless switched off) the operator's real
     * aggregate counts. Dot.Analytics drops keys its catalogue does not list yet, so the extra keys cannot break
     * the snapshot; a failure while computing them leaves the three untouched. `null` (no data yet) is left out
     * rather than sent, so "not reported" never turns into a zero.
     *
     * @return array<string, int>
     */
    private function metrics(CarbonInterface $asOf): array
    {
        $metrics = $this->collector->collect($asOf);

        if (! config('analytics.extended', false)) {
            return $metrics;
        }

        try {
            foreach ($this->collector->extended($asOf) as $key => $value) {
                if ($value !== null && ! array_key_exists($key, $metrics)) {
                    $metrics[$key] = (int) $value;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Could not compute the extra operator metrics; sending the core snapshot only.', ['error' => $e->getMessage()]);
        }

        return $metrics;
    }

    /**
     * Queue today's snapshot. Returns the payload, or null when publishing is off or the job could not
     * be queued. A collector (database) error is allowed to surface to the command; nothing else is.
     *
     * @return array<string, mixed>|null
     */
    public function publish(?CarbonInterface $asOf = null): ?array
    {
        if (! $this->client->enabled()) {
            return null;
        }

        $payload = $this->payload($asOf);

        try {
            app(Dispatcher::class)->dispatch(new SendAnalyticsSnapshot($payload));
        } catch (Throwable $e) {
            Log::warning('Could not queue the Dot.Analytics snapshot.', ['error' => $e->getMessage()]);

            return null;
        }

        return $payload;
    }
}

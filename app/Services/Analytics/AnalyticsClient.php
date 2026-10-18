<?php

namespace App\Services\Analytics;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one daily snapshot to Dot.Analytics (POST config('analytics.url'), ending /api/ingest/snapshots).
 *
 * Built to be harmless when things go wrong: it is only ever called from a queued job, the request
 * has a 5 second timeout, nothing here throws into the caller, a circuit breaker leaves a struggling
 * Analytics alone, and with no url, secret or company uuid it does nothing at all.
 *
 * Signing: HMAC-SHA256 over "{timestamp}.{raw body}" with the shared secret, sent as
 * "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp. The signature is
 * made at SEND time, so a retry hours later carries a fresh timestamp. The day it describes
 * (taken_at) is fixed in the payload, so a retry or a re-run is an idempotent replay upstream.
 */
class AnalyticsClient
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    private const FAILURES_KEY = 'analytics:publish:failures';

    private const OPEN_KEY = 'analytics:publish:open';

    public function enabled(): bool
    {
        return filled(config('analytics.url'))
            && filled(config('analytics.secret'))
            && filled(config('analytics.company_uuid'));
    }

    /**
     * POST one snapshot now. Returns what the queued job should do next; never throws.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(array $payload): string
    {
        if (! $this->enabled()) {
            return self::GIVE_UP;
        }

        if ($this->circuitOpen()) {
            Log::info('Dot.Analytics circuit is open; snapshot not sent this time.');

            return self::RETRY;
        }

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('analytics.timeout', 5);

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('analytics.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('analytics.url'));
        } catch (ConnectionException $e) {
            Log::warning('Dot.Analytics unreachable; will retry.', ['error' => class_basename($e)]);
            $this->recordFailure();

            return self::RETRY;
        } catch (Throwable $e) {
            Log::warning('Dot.Analytics call failed unexpectedly; will retry.', ['error' => $e->getMessage()]);
            $this->recordFailure();

            return self::RETRY;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return self::DONE;
        }

        if ($status === 429 || $status >= 500) {
            Log::warning('Dot.Analytics unavailable; will retry.', ['status' => $status]);
            $this->recordFailure();

            return self::RETRY;
        }

        // 401/403: the secret is wrong; 404: the company is unknown; 422: the payload was refused.
        // Retrying cannot fix these, and they prove Analytics itself is up.
        $this->recordSuccess();
        Log::error('Dot.Analytics refused the snapshot.', ['status' => $status, 'platform' => $payload['platform'] ?? null]);

        return self::GIVE_UP;
    }

    public function circuitOpen(): bool
    {
        try {
            return (bool) Cache::get(self::OPEN_KEY, false);
        } catch (Throwable) {
            return false; // a broken cache must never stop (or break) delivery
        }
    }

    private function recordFailure(): void
    {
        try {
            $threshold = max(1, (int) config('analytics.breaker.threshold', 3));
            $cooldown = max(1, (int) config('analytics.breaker.cooldown', 300));

            Cache::add(self::FAILURES_KEY, 0, now()->addDay());
            $failures = (int) Cache::increment(self::FAILURES_KEY);

            // The count is kept after the breaker opens, so a failed trial once the cooldown has
            // passed re-opens it at once. Only a success starts the count over.
            if ($failures >= $threshold) {
                Cache::put(self::OPEN_KEY, true, now()->addSeconds($cooldown));
                Log::warning('Dot.Analytics circuit opened.', ['cooldown_seconds' => $cooldown]);
            }
        } catch (Throwable) {
            // ignore: see circuitOpen()
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget(self::FAILURES_KEY);
            Cache::forget(self::OPEN_KEY);
        } catch (Throwable) {
            // ignore: see circuitOpen()
        }
    }
}

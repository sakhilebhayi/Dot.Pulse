<?php

namespace App\Services\InfoDot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks InfoDot whether a company holds this platform, and whether the platform is available (R-50, R-61).
 *
 * One question, about one company, answered from InfoDot's read endpoint with this platform's own service secret. A
 * clear answer is kept for one minute per company, so a switch made at InfoDot (a platform put in maintenance) takes
 * effect within about a minute, and is also remembered for six hours. Anything that is not a clear answer (InfoDot
 * down, slow, refusing, a body this app cannot read, no secret configured) is "unknown": the last clear answer
 * under six hours old is used, so a platform last told "unavailable" stays unavailable; with none, the caller carries
 * on as if the check did not exist. An outage at InfoDot never locks a company out that was last told it could work.
 * A failure is logged once per window.
 */
class EntitlementClient
{
    /** How long one clear answer is used before InfoDot is asked again. */
    public const ANSWER_TTL = 60;

    /** How long "no answer" is remembered, so a broken InfoDot is not asked on every request. */
    public const FAILURE_TTL = 60;

    /** How long the last clear answer is used when InfoDot cannot be reached. */
    public const LAST_KNOWN_TTL = 21600;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.infodot.entitlement_check', true);
    }

    /**
     * @return array{entitled: bool, status: string, available: bool, message: ?string, features: array<string, array{state: string, message: ?string}>}|null null when InfoDot gave no clear answer and none is remembered
     */
    public function forCompany(string $uuid): ?array
    {
        $key = 'infodot:entitlement:'.$this->platform().':'.$uuid;

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }

        // An entry from before R-61 has no `answer` key and is read as a miss.
        if (is_array($cached) && array_key_exists('answer', $cached)) {
            return $cached['answer'];
        }

        $answer = $this->ask($uuid);

        if (is_string($answer)) {
            Log::warning('InfoDot entitlement check gave no answer; carrying on without it.', ['company' => $uuid, 'reason' => $answer]);
            $answer = null;
            $fresh = false;
        } else {
            $fresh = true;
        }

        try {
            if ($fresh) {
                Cache::put($key.':last', ['answer' => $answer, 'at' => time()], self::LAST_KNOWN_TTL);
            } else {
                $last = Cache::get($key.':last');
                $answer = is_array($last) && is_array($last['answer'] ?? null) && time() - (int) ($last['at'] ?? 0) < self::LAST_KNOWN_TTL ? $last['answer'] : null;
            }

            Cache::put($key, ['answer' => $answer], $answer === null ? self::FAILURE_TTL : self::ANSWER_TTL);
        } catch (Throwable) {
            // No cache, no memory of the answer: the next request asks again.
        }

        return $answer;
    }

    /**
     * @return array{entitled: bool, status: string, available: bool, message: ?string, features: array<string, array{state: string, message: ?string}>}|string the answer, or why there is none
     */
    private function ask(string $uuid): array|string
    {
        $platform = $this->platform();
        $credential = (string) config('ecosystem.infodot.secret');
        $url = $this->url($uuid);

        if ($credential === '' || $platform === '' || $url === null) {
            return 'not configured';
        }

        try {
            $response = Http::withToken($credential)
                ->withHeaders(['X-Dot-Platform' => $platform])
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(max(1, min(3, (int) config('ecosystem.infodot.entitlement_timeout', 3))))
                ->get($url, ['platform' => $platform]);
        } catch (Throwable $e) {
            return 'unreachable ('.class_basename($e).')';
        }

        if (! $response->successful()) {
            return 'status '.$response->status();
        }

        $body = $response->json();

        if (! is_array($body) || ($body['platform'] ?? null) !== $platform || ! is_array($body['platforms'] ?? null)) {
            return 'malformed body';
        }

        foreach ($body['platforms'] as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $platform) {
                if (! is_bool($row['entitled'] ?? null)) {
                    return 'malformed body';
                }

                return [
                    'entitled' => $row['entitled'],
                    'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
                    // R-61: an InfoDot from before contract 2 gives no `available`, which is not a switch-off.
                    'available' => ($row['available'] ?? true) !== false,
                    'message' => $this->text($row['message'] ?? null),
                    'features' => $this->features($row['features'] ?? null),
                ];
            }
        }

        return 'own row missing';
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 400) : null;
    }

    /**
     * The feature switches in this platform's own row (R-61), kept as key => state and message. Anything malformed is
     * ignored, and a feature that is not listed is available.
     *
     * @return array<string, array{state: string, message: ?string}>
     */
    private function features(mixed $raw): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $key => $row) {
            if (is_string($key) && is_array($row) && is_string($row['state'] ?? null)) {
                $out[$key] = ['state' => $row['state'], 'message' => $this->text($row['message'] ?? null)];
            }
        }

        return $out;
    }

    private function platform(): string
    {
        return (string) config('ecosystem.infodot.platform');
    }

    /** The read endpoint sits beside the redeem endpoint: .../api/ecosystem/companies/{uuid}/platforms. */
    private function url(string $uuid): ?string
    {
        $configured = config('ecosystem.infodot.entitlement_url');

        if (is_string($configured) && $configured !== '') {
            return str_replace('{uuid}', rawurlencode($uuid), $configured);
        }

        $redeem = (string) config('ecosystem.infodot.redeem_url');

        if ($redeem === '' || ! preg_match('#/redeem/?$#', $redeem)) {
            return null;
        }

        return preg_replace('#/redeem/?$#', '', $redeem).'/companies/'.rawurlencode($uuid).'/platforms';
    }
}

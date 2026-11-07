<?php

namespace App\Services\Pulse\Discovery\Digest;

use App\Models\PulseNotificationPref;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\BeatBuilder;
use App\Services\Pulse\Discovery\ContextLoader;
use App\Services\Pulse\Discovery\Matcher;
use App\Services\Pulse\Discovery\SignalPack;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * What goes in a person's digest: the best new matches since the last one, each with the reason it was picked.
 * "New" means published since the last digest. Returns null when there is nothing real to say, and then no email is sent. It is not marketing: every item is
 * one of the person's own matches, nothing is added to fill space, and a business cannot place anything in it.
 */
class DigestBuilder
{
    public const MIN_SCORE = 20.0;

    private const KIND_LABELS = [
        'offer' => 'Offer', 'need' => 'Need', 'role' => 'Role', 'talent' => 'Professional', 'news' => 'News',
        'ask' => 'Question', 'event' => 'Event', 'collab' => 'Collaboration', 'business' => 'Business',
    ];

    public function __construct(
        private readonly ContextLoader $contexts,
        private readonly Matcher $matcher,
        private readonly BeatBuilder $beats,
    ) {}

    /**
     * @return array{subject: string, intro: string, items: list<array<string, mixed>>, count: int, more: int, since: CarbonInterface, floor_url: string, manage_url: string, unsubscribe_url: string}|null
     */
    public function build(User $u): ?array
    {
        $prefs = PulseNotificationPref::where('user_id', $u->id)->first();
        $since = $prefs?->last_digest_at ?? now()->subDays(($prefs?->frequency === 'weekly') ? 7 : 2);

        $rows = PulseSignal::query()
            ->join('pulse_matches as m', 'm.signal_id', '=', 'pulse_signals.id')
            ->where('m.user_id', $u->id)
            ->whereNull('m.dismissed_at')
            ->whereNull('m.seen_at')
            ->where('m.score', '>=', self::MIN_SCORE)
            ->where('pulse_signals.published_at', '>=', $since)
            ->where('pulse_signals.status', 'live')
            ->where(fn ($w) => $w->whereNull('pulse_signals.expires_at')->orWhere('pulse_signals.expires_at', '>', now()))
            ->whereNotIn('pulse_signals.id', fn ($q) => $q->select('saveable_id')->from('pulse_saves')->where('user_id', $u->id)->where('saveable_type', 'signal'))
            ->orderByDesc('m.score')->orderByDesc('pulse_signals.published_at')
            ->limit(60)
            ->get(['pulse_signals.*', 'm.id as match_id', 'm.score as match_score', 'm.reasons as match_reasons']);

        if ($rows->isEmpty()) {
            return null;
        }

        $pack = SignalPack::build($rows);
        $ctx = $this->contexts->loadExclusions($u->id);
        $max = (int) config('pulse.digest.max_items', 6);
        $perKind = [];
        $picked = collect();
        $matches = [];
        $eligible = 0;

        foreach ($rows as $signal) {
            if (! $this->matcher->eligible($ctx, $signal, $pack)) {
                continue;
            }

            $reasons = is_string($signal->match_reasons) ? (json_decode($signal->match_reasons, true) ?: []) : (array) $signal->match_reasons;

            if ($reasons === []) {
                continue; // an item without a reason is never shown
            }

            $eligible++;

            if ($picked->count() >= $max || ($perKind[$signal->kind] ?? 0) >= 3) {
                continue;
            }

            $perKind[$signal->kind] = ($perKind[$signal->kind] ?? 0) + 1;
            $matches[$signal->id] = ['id' => (int) $signal->match_id, 'score' => (float) $signal->match_score, 'reasons' => $reasons];
            $picked->push($signal);
        }

        if ($picked->isEmpty()) {
            return null;
        }

        $items = $this->beats->build($u->id, $picked, $pack, $matches)->map(fn ($b) => [
            'signal_id' => $b->signal->id,
            'kind' => $b->kind,
            'kind_label' => self::KIND_LABELS[$b->kind] ?? ucfirst($b->kind),
            'title' => $b->title,
            'excerpt' => $b->excerpt,
            'from' => $b->from['name'],
            'place' => $b->place['label'] ?? null,
            'reason' => $b->reasons[0]['text'] ?? '',
            'reasons' => array_column($b->reasons, 'text'),
            'url' => $b->url ?? $this->floorUrl(),
            'score' => $b->score,
        ])->all();

        $count = count($items);

        return [
            'subject' => $count === 1 ? '1 thing on the Floor that fits you' : "{$count} things on the Floor that fit you",
            'intro' => $count === 1
                ? 'One new thing matches what you said you do or need. Here is why.'
                : "{$count} new things match what you said you do or need. Each one says why.",
            'items' => $items,
            'count' => $count,
            'more' => max(0, $eligible - $count),
            'since' => $since,
            'floor_url' => $this->floorUrl(),
            'manage_url' => $this->manageUrl(),
            'unsubscribe_url' => $this->unsubscribeUrl($u),
        ];
    }

    public function floorUrl(): string
    {
        return Route::has('floor') ? route('floor') : url('/floor');
    }

    public function manageUrl(): string
    {
        return Route::has('me.notifications') ? route('me.notifications') : url('/me/notifications');
    }

    public function unsubscribeUrl(User $u): string
    {
        return Route::has('pulse.digest.unsubscribe')
            ? URL::temporarySignedRoute('pulse.digest.unsubscribe', now()->addDays(90), ['user' => $u->id])
            : $this->manageUrl();
    }
}

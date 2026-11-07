<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulseSignal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing on the Floor: a signal, what it points at, who it is from and, always, why you are seeing it.
 *
 * Privacy: for an anonymous talent signal `signal` is a copy with the owner removed, `subject` is null, `from` has no
 * name, avatar or entity, and `card` carries only what an employer may see (headline, skills, experience band,
 * region). Nothing in a Beat for such a signal can lead back to the person.
 */
final class Beat
{
    /**
     * @param  array{name: ?string, avatar: ?string, anonymous: bool, entity: ?array<string, mixed>, ladder: ?array<string, mixed>}  $from
     * @param  array{id: ?int, slug: ?string, name: string, label: string, level: string}|null  $place
     * @param  list<array{code: string, text: string, weight: float, ref: ?string}>  $reasons
     * @param  array{allowed: bool, mode: string, why: ?string}  $can_knock
     * @param  array<string, mixed>|null  $card
     */
    public function __construct(
        public readonly PulseSignal $signal,
        public readonly ?Model $subject,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $excerpt,
        public readonly array $from,
        public readonly ?array $place,
        public readonly array $reasons,
        public readonly float $score,
        public readonly ?CarbonInterface $published_at,
        public readonly array $can_knock,
        public readonly bool $saved,
        public readonly ?int $match_id = null,
        public readonly ?array $card = null,
        public readonly ?string $url = null,
        public readonly array $topics = [],
    ) {}

    /** A plain array safe to hand to a view or a Livewire property: ids and text, no models. */
    public function toArray(): array
    {
        return [
            'signal_id' => $this->signal->id,
            'match_id' => $this->match_id,
            'kind' => $this->kind,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'from' => $this->from,
            'place' => $this->place,
            'reasons' => $this->reasons,
            'score' => $this->score,
            'published_at' => $this->published_at?->toIso8601String(),
            'can_knock' => $this->can_knock,
            'saved' => $this->saved,
            'card' => $this->card,
            'url' => $this->url,
            'topics' => $this->topics,
            'subject_type' => $this->signal->subject_type,
            'subject_id' => $this->from['anonymous'] ? null : $this->signal->subject_id,
            'anonymous' => (bool) $this->signal->anonymous,
        ];
    }
}

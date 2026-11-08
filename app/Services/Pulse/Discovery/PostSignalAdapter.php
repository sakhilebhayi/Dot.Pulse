<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulsePost;
use App\Models\PulseSignal;
use App\Services\Pulse\Discovery\Support\SubjectTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Existing posts (type "post") appear on the Floor as `news` or `ask` signals. This class is the whole mapping.
 * D1's SignalIndexer calls toSignalAttributes() for post subjects; sync() is the stand-alone path used by the
 * moderation step and by pulse:index when the indexer is not installed.
 *
 *   question          -> ask
 *   event             -> event
 *   everything else   -> news
 *
 * Only published posts are `live`. Pending and flagged posts are `held`, removed or deleted posts are `removed`, and
 * a post in a private or enterprise community is never discoverable (`paused`): it must not leave its community.
 */
class PostSignalAdapter
{
    /** @return array<string, mixed> attributes for a pulse_signals row (upsert key: subject_type + subject_id) */
    public function toSignalAttributes(PulsePost $post): array
    {
        $text = trim(($post->title ? $post->title.'. ' : '').$post->body);
        $enrichment = $post->relationLoaded('enrichment') ? $post->enrichment : $post->enrichment()->first();

        $tags = array_merge(
            (array) ($enrichment?->topics ?? []),
            (array) ($enrichment?->tags ?? []),
            $post->hashtags()->pluck('name')->all(),
        );
        $topics = array_slice(array_values(array_unique(array_merge(Taxonomy::normalise($tags, 10), Taxonomy::extract($text, 8)))), 0, 12);

        $title = $post->title ?: Str::limit(preg_replace('/\s+/', ' ', trim($post->body)) ?? '', 100, '');
        $excerpt = Str::limit(preg_replace('/\s+/', ' ', trim($post->body)) ?? '', 277);

        $engagement = min(15, (int) $post->reactions_count + 2 * (int) $post->comments_count) / 50;
        $quality = 0.4 + ($post->title ? 0.1 : 0.0) + $engagement - 0.5 * (float) ($enrichment?->spam_score ?? 0);

        return [
            'kind' => match ($post->type) {
                'question' => 'ask',
                'event' => 'event',
                default => 'news',
            },
            'subject_type' => 'post',
            'subject_id' => $post->id,
            'owner_id' => $post->user_id,
            'entity_id' => $post->entity_id ?? null,
            'title' => Str::limit($title, 157, ''),
            'excerpt' => $excerpt !== '' ? $excerpt : null,
            'topics' => $topics,
            'skills' => [],
            'place_id' => DB::table('pulse_profiles')->where('user_id', $post->user_id)->value('place_id'),
            'reach' => 'national',
            'is_remote' => false,
            'anonymous' => false,
            'quality' => round(max(0.0, min(1.0, $quality)), 2),
            'status' => $this->status($post),
            'published_at' => $post->published_at ?? $post->created_at ?? now(),
            'expires_at' => null,
        ];
    }

    /**
     * Create or update the signal for a post. Does not create a signal for a post that is not published yet; does
     * update (and so hide) an existing one when the post is held, removed or deleted.
     */
    public function sync(PulsePost $post): ?PulseSignal
    {
        $existing = PulseSignal::whereIn('subject_type', SubjectTypes::variants('post'))->where('subject_id', $post->id)->first();
        $attrs = $this->toSignalAttributes($post);

        if ($existing === null) {
            return $attrs['status'] === 'live' ? PulseSignal::create($attrs) : null;
        }

        // Keep whatever spelling of subject_type the row already has, so the unique key never forks.
        unset($attrs['subject_type']);
        $existing->fill($attrs)->save();

        return $existing;
    }

    /** Index every published post that has no signal yet and refresh the ones changed since $since. Returns rows touched. */
    public function syncAll(?\DateTimeInterface $since = null): int
    {
        $n = 0;

        PulsePost::withTrashed()
            ->where(fn ($q) => $q->where('status', 'published')->orWhereIn('id', fn ($s) => $s->select('subject_id')->from('pulse_signals')->whereIn('subject_type', SubjectTypes::variants('post'))))
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->chunkById(200, function ($posts) use (&$n) {
                foreach ($posts as $post) {
                    $this->sync($post);
                    $n++;
                }
            });

        return $n;
    }

    private function status(PulsePost $post): string
    {
        if ($post->trashed()) {
            return 'removed';
        }

        if ($post->community_id !== null) {
            $visibility = DB::table('communities')->where('id', $post->community_id)->value('visibility');

            if ($visibility !== 'public') {
                return 'paused';
            }
        }

        return match ($post->status) {
            'published' => 'live',
            'pending', 'flagged' => 'held',
            'removed' => 'removed',
            default => 'paused',
        };
    }
}

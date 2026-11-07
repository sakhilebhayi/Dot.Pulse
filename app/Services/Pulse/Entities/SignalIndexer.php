<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePlace;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps pulse_signals, the one index discovery reads, in step with its subjects.
 *
 * subject_type is a short name: offer, need, role, profile (talent), post, entity (business).
 * A subject that should not be indexed (draft, deleted, removed, a person who is not discoverable) has its row set
 * to "removed" if it had one; no row is created for it.
 *
 * Status of a row: live | paused | expired | removed | held.
 * - held: the text is awaiting moderation (pending or held), or the page it belongs to is.
 * - paused: the owner paused it, or its page is not live.
 * - expired: closed, filled, fulfilled or past its end date.
 *
 * `quality` is 0..100 and measures how complete the listing is (media, price, place, topics, description). It never
 * reads clicks, reactions or any other engagement.
 */
class SignalIndexer
{
    public const TYPES = [
        PulseOffer::class => 'offer',
        PulseNeed::class => 'need',
        PulseRole::class => 'role',
        PulseProfile::class => 'profile',
        PulsePost::class => 'post',
        PulseEntity::class => 'entity',
    ];

    public static function subjectType(Model $subject): ?string
    {
        foreach (self::TYPES as $class => $type) {
            if ($subject instanceof $class) {
                return $type;
            }
        }

        return null;
    }

    /** Upserts (or retires) the signal row of a subject. Idempotent: running it twice changes nothing. */
    public function syncSubject(Model $subject): ?PulseSignal
    {
        $type = self::subjectType($subject);

        if ($type === null) {
            return null;
        }

        $attributes = match ($type) {
            'offer' => $this->forOffer($subject),
            'need' => $this->forNeed($subject),
            'role' => $this->forRole($subject),
            'profile' => $this->forProfile($subject),
            'post' => $this->forPost($subject),
            'entity' => $this->forEntity($subject),
        };

        if ($attributes === null) {
            $this->retire($subject);

            return PulseSignal::forSubject($type, (int) $subject->getKey())->first();
        }

        return $this->write($type, (int) $subject->getKey(), $attributes);
    }

    /** The subject is gone or no longer indexable: the row (if any) stops being shown anywhere. */
    public function retire(Model $subject): void
    {
        $type = self::subjectType($subject);

        if ($type === null || ! $subject->getKey()) {
            return;
        }

        $query = PulseSignal::forSubject($type, (int) $subject->getKey());

        if (method_exists($subject, 'isForceDeleting') && $subject->isForceDeleting()) {
            $query->delete();

            return;
        }

        $query->where('status', '!=', 'removed')->update(['status' => 'removed', 'updated_at' => now()]);
    }

    /** Re-syncs every subject and drops rows whose subject no longer exists. Returns the number of subjects synced. */
    public function rebuild(): int
    {
        $count = 0;
        $each = function ($query) use (&$count) {
            $query->chunkById(200, function ($models) use (&$count) {
                foreach ($models as $model) {
                    $this->syncSubject($model);
                    $count++;
                }
            });
        };

        $each(PulseEntity::withTrashed()->with('place'));
        $each(PulseOffer::withTrashed()->with(['entity', 'place']));
        $each(PulseRole::withTrashed()->with(['entity', 'place']));
        $each(PulseNeed::withTrashed()->with('place'));
        $each(PulseProfile::query()->with(['user', 'place']));
        $each(PulsePost::withTrashed()->with('community')->where('status', 'published'));

        foreach ([
            'offer' => 'pulse_offers', 'need' => 'pulse_needs', 'role' => 'pulse_roles',
            'profile' => 'pulse_profiles', 'post' => 'pulse_posts', 'entity' => 'pulse_entities',
        ] as $type => $table) {
            PulseSignal::query()->where('subject_type', $type)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($table)->whereColumn("$table.id", 'pulse_signals.subject_id'))
                ->delete();
        }

        return $count;
    }

    /** @param array<string, mixed> $attributes */
    private function write(string $type, int $id, array $attributes): PulseSignal
    {
        $attributes['published_at'] ??= now();

        try {
            return $this->upsert($type, $id, $attributes);
        } catch (UniqueConstraintViolationException) {
            return $this->upsert($type, $id, $attributes); // a concurrent writer made the row first
        }
    }

    /** @param array<string, mixed> $attributes */
    private function upsert(string $type, int $id, array $attributes): PulseSignal
    {
        $signal = PulseSignal::firstOrNew(['subject_type' => $type, 'subject_id' => $id]);
        $signal->fill($attributes);

        if (! $signal->exists || $signal->isDirty()) {
            $signal->save();
        }

        return $signal;
    }

    // ---- builders -------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function forOffer(PulseOffer $offer): ?array
    {
        $entity = $offer->entity;

        if ($offer->trashed() || ! $entity || in_array($offer->status, ['draft', 'archived', 'removed'], true) || $offer->moderation === 'removed') {
            return null;
        }

        $place = $offer->place ?? $entity->place;
        $area = (array) ($offer->service_area ?: $entity->service_area);

        return [
            'kind' => 'offer',
            'owner_id' => $offer->created_by,
            'entity_id' => $entity->id,
            'title' => Str::limit((string) $offer->title, 160, ''),
            'excerpt' => $this->excerpt($offer->summary ?: $offer->description),
            'topics' => $this->slugs([$offer->category, ...(array) $offer->tags]),
            'skills' => [],
            'place_id' => $place?->id,
            'reach' => $this->reach($place, $area),
            'is_remote' => $this->remote($area),
            'anonymous' => false,
            'quality' => $this->quality([
                [20, ! empty($offer->media_ids)],
                [15, in_array($offer->price_mode, ['fixed', 'from'], true) ? $offer->amount_cents > 0 : in_array($offer->price_mode, ['free'], true)],
                [15, $place !== null || $this->remote($area)],
                [15, ! empty($offer->tags) || filled($offer->category)],
                [25, mb_strlen((string) $offer->description) >= 120],
                [10, filled($offer->summary)],
            ]),
            'status' => $this->status(
                expired: $offer->expires_at?->isPast() ?? false,
                paused: $offer->status === 'paused',
                moderation: $offer->moderation,
                entity: $entity,
            ),
            'published_at' => $offer->published_at ?? $offer->created_at,
            'expires_at' => $offer->expires_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function forRole(PulseRole $role): ?array
    {
        $entity = $role->entity;

        if ($role->trashed() || ! $entity || in_array($role->status, ['draft', 'removed'], true) || $role->moderation === 'removed') {
            return null;
        }

        $place = $role->place ?? $entity->place;

        return [
            'kind' => 'role',
            'owner_id' => $role->created_by,
            'entity_id' => $entity->id,
            'title' => Str::limit((string) $role->title, 160, ''),
            'excerpt' => $this->excerpt($role->summary ?: $role->description),
            'topics' => $this->slugs([$entity->industry, $role->seniority]),
            'skills' => $this->slugs((array) $role->skills, 15),
            'place_id' => $place?->id,
            'reach' => $role->work_mode === 'remote' ? 'national' : $this->reach($place, []),
            'is_remote' => $role->work_mode === 'remote',
            'anonymous' => false,
            'quality' => $this->quality([
                [25, count((array) $role->skills) >= 3],
                [20, filled($role->pay_text)],
                [15, $place !== null || $role->work_mode === 'remote'],
                [10, filled($role->seniority)],
                [20, mb_strlen((string) $role->description) >= 150],
                [10, filled($role->summary)],
            ]),
            'status' => $this->status(
                expired: in_array($role->status, ['filled', 'closed'], true) || ($role->closes_at?->isPast() ?? false),
                paused: $role->status === 'paused',
                moderation: $role->moderation,
                entity: $entity,
            ),
            'published_at' => $role->published_at ?? $role->created_at,
            'expires_at' => $role->closes_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function forNeed(PulseNeed $need): ?array
    {
        if ($need->trashed() || $need->status === 'removed' || $need->moderation === 'removed') {
            return null;
        }

        $place = $need->place;
        $reach = match ($need->reach) {
            'city', 'local' => 'local',
            'country', 'national' => 'national',
            'remote', 'global' => 'global',
            default => 'region',
        };

        return [
            'kind' => match ($need->kind) {
                'collab', 'investor' => 'collab',
                'mentor', 'intro' => 'ask',
                default => 'need',
            },
            'owner_id' => $need->user_id,
            'entity_id' => $need->entity_id,
            'title' => Str::limit((string) $need->title, 160, ''),
            'excerpt' => $this->excerpt($need->details),
            'topics' => $this->slugs((array) $need->tags),
            'skills' => [],
            'place_id' => $place?->id,
            'reach' => $reach,
            'is_remote' => $need->reach === 'remote' || $need->reach === 'global',
            'anonymous' => false,
            'quality' => $this->quality([
                [30, ! empty($need->tags)],
                [20, $place !== null || in_array($reach, ['global'], true)],
                [20, filled($need->budget_text)],
                [10, $need->needed_by !== null],
                [20, mb_strlen((string) $need->details) >= 80],
            ]),
            'status' => $this->status(
                expired: in_array($need->status, ['fulfilled', 'closed'], true) || ($need->expires_at?->isPast() ?? false),
                paused: false,
                moderation: $need->moderation,
                entity: null,
            ),
            'published_at' => $need->published_at ?? $need->created_at,
            'expires_at' => $need->expires_at,
        ];
    }

    /**
     * Talent. Exists only for a discoverable profile with the 18+ confirmation. For an anonymous profile nothing
     * that names the person goes in: the title and excerpt are scrubbed of the person's name parts, emails, phone
     * numbers and links, the excerpt is built from skills and the experience band only, and the place is the region.
     *
     * @return array<string, mixed>|null
     */
    private function forProfile(PulseProfile $profile): ?array
    {
        $user = $profile->user;

        if (! $user || ! $profile->isDiscoverable()) {
            return null;
        }

        $anonymous = $profile->discoverability === 'anonymous';
        $place = $profile->show_place ? $profile->place : null;

        if ($anonymous) {
            $place = self::regionOf($place);
        }

        // An anonymous person must not be named through the words they typed either: the places they have worked go too.
        $orgs = $anonymous ? collect((array) $profile->work_history)->map(fn ($j) => is_array($j) ? (string) ($j['org'] ?? $j['employer'] ?? '') : '')->filter(fn ($o) => mb_strlen(trim($o)) >= 3)->values()->all() : [];
        $scrub = fn (?string $text) => $this->scrub($text, $user->name, $user->email, $orgs);
        $skills = array_values(array_filter((array) $profile->skills));
        $band = self::experienceBand($profile->experience_years);

        $title = $scrub($profile->headline) ?: ($skills ? Str::limit(implode(', ', array_slice($skills, 0, 3)), 120, '') : 'Professional open to opportunities');

        $excerpt = $anonymous
            ? trim(($band ? $band['label'].'. ' : '').($skills ? implode(', ', array_slice($skills, 0, 6)).'.' : ''))
            : $scrub($profile->bio);

        $reach = match ($profile->reach) {
            'city' => 'local',
            'country' => 'national',
            'remote' => 'global',
            default => 'region',
        };

        return [
            'kind' => 'talent',
            'owner_id' => $user->id,
            'entity_id' => null,
            'title' => Str::limit($title, 160, ''),
            'excerpt' => $this->excerpt($excerpt),
            'topics' => $this->slugs((array) $profile->interests),
            'skills' => $this->slugs($skills, 15),
            'place_id' => $place?->id,
            'reach' => $reach,
            'is_remote' => $profile->reach === 'remote' || in_array('remote', (array) $profile->open_to, true),
            'anonymous' => $anonymous,
            'quality' => $this->quality([
                [20, filled($profile->headline)],
                [25, count($skills) >= 3],
                [15, $profile->experience_years !== null],
                [15, $place !== null],
                [10, ! empty($profile->languages)],
                [15, mb_strlen((string) $profile->bio) >= 80],
            ]),
            'status' => $profile->moderation === 'approved' ? 'live' : 'held',
            'published_at' => $profile->onboarded_at ?? $profile->age_confirmed_at ?? $profile->created_at,
            'expires_at' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function forEntity(PulseEntity $entity): ?array
    {
        if ($entity->trashed() || $entity->status !== 'live' || $entity->moderation === 'removed') {
            return null;
        }

        $place = $entity->place;
        $area = (array) $entity->service_area;
        $hiring = $entity->hasHiringSignal();

        return [
            'kind' => 'business',
            'owner_id' => $entity->created_by,
            'entity_id' => $entity->id,
            'title' => Str::limit((string) $entity->name, 160, ''),
            'excerpt' => $this->excerpt($entity->tagline ?: $entity->about),
            'topics' => $this->slugs([$entity->industry]),
            'skills' => $hiring ? $this->slugs((array) $entity->hiring_skills, 15) : [],
            'place_id' => $place?->id,
            'reach' => $this->reach($place, $area),
            'is_remote' => $this->remote($area),
            'anonymous' => false,
            'quality' => $this->quality([
                [20, $entity->logo_media_id !== null],
                [15, $entity->cover_media_id !== null],
                [15, filled($entity->tagline)],
                [20, mb_strlen((string) $entity->about) >= 120],
                [15, $place !== null],
                [15, filled($entity->industry)],
            ]),
            'status' => $entity->moderation === 'approved' ? 'live' : 'held',
            'published_at' => $entity->created_at,
            'expires_at' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function forPost(PulsePost $post): ?array
    {
        $kind = match ($post->type) {
            'question' => 'ask',
            'announcement', 'release', 'article', 'success_story' => 'news',
            'event' => 'event',
            default => null,
        };

        if ($kind === null || $post->trashed() || $post->status !== 'published') {
            return null;
        }

        if ($post->community_id !== null && $post->community?->visibility !== 'public') {
            return null; // private and enterprise communities never enter the index
        }

        $body = (string) $post->body;

        return [
            'kind' => $kind,
            'owner_id' => $post->user_id,
            'entity_id' => $post->getAttribute('entity_id'),
            'title' => Str::limit($post->title ?: Str::limit($body, 100, ''), 160, ''),
            'excerpt' => $this->excerpt($body),
            'topics' => $this->slugs($post->hashtags()->pluck('name')->all()),
            'skills' => [],
            'place_id' => null,
            'reach' => 'global',
            'is_remote' => true,
            'anonymous' => false,
            'quality' => $this->quality([
                [40, mb_strlen($body) >= 120],
                [20, filled($post->title)],
                [40, $post->hashtags()->exists()],
            ]),
            'status' => 'live',
            'published_at' => $post->published_at ?? $post->created_at,
            'expires_at' => null,
        ];
    }

    // ---- helpers --------------------------------------------------------------------------------------------

    /** Order of precedence: ended, paused, awaiting moderation, then the state of the page it hangs from. */
    private function status(bool $expired, bool $paused, string $moderation, ?PulseEntity $entity): string
    {
        if ($expired) {
            return 'expired';
        }

        if ($paused) {
            return 'paused';
        }

        if ($moderation !== 'approved') {
            return 'held';
        }

        if ($entity === null || $entity->isLive()) {
            return 'live';
        }

        return in_array($entity->moderation, ['pending', 'held'], true) && $entity->status === 'live' ? 'held' : 'paused';
    }

    /** @param list<array{0: int, 1: bool}> $checks [weight, passed]; the weights add up to 100 */
    private function quality(array $checks): float
    {
        $score = 0;

        foreach ($checks as [$weight, $passed]) {
            $score += $passed ? $weight : 0;
        }

        return (float) min(100, $score);
    }

    private function excerpt(?string $text): ?string
    {
        $text = Sanitizer::line($text, 2000);

        return $text === null ? null : Str::limit($text, 280, '');
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function slugs(iterable $values, int $max = 12): array
    {
        $out = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $slug = Str::limit(Str::slug((string) $value), 40, '');

            if ($slug !== '' && ! in_array($slug, $out, true)) {
                $out[] = $slug;
            }

            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** @param list<string> $area */
    private function reach(?PulsePlace $place, array $area): string
    {
        if (in_array('global', $area, true)) {
            return 'global';
        }

        if (in_array('national', $area, true)) {
            return 'national';
        }

        // A signal that names no place cannot be local or regional: nobody could be near a place that is not there,
        // and the matcher would never show it to anyone. Without a place it is as wide as the country.
        return match ($place?->level) {
            null => 'national',
            'city' => 'local',
            'country' => 'national',
            default => 'region',
        };
    }

    /** @param list<string> $area */
    private function remote(array $area): bool
    {
        return in_array('remote', $area, true) || in_array('global', $area, true);
    }

    /** City to its region (so an anonymous person is only ever as precise as a region). */
    public static function regionOf(?PulsePlace $place): ?PulsePlace
    {
        if ($place === null || $place->level !== 'city') {
            return $place;
        }

        return PulsePlace::query()->where('level', 'region')->where('country_code', $place->country_code)->where('region', $place->region)->first()
            ?? PulsePlace::query()->where('level', 'country')->where('country_code', $place->country_code)->first();
    }

    /** Removes a person's name parts, email and contact details from text that must not identify them. */
    private function scrub(?string $text, string $name, string $email, array $phrases = []): ?string
    {
        $text = Sanitizer::withoutContactDetails($text);

        if ($text === null) {
            return null;
        }

        // Whole phrases first (an employer's name), longest first, so a part of one is not left behind.
        usort($phrases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($phrases as $phrase) {
            $text = (string) preg_replace('/'.preg_quote(trim($phrase), '/').'/iu', '', $text);
        }

        $parts = array_filter(preg_split('/[\s.\-_]+/u', $name.' '.Str::before($email, '@')) ?: [], fn ($p) => mb_strlen($p) >= 3);

        foreach ($parts as $part) {
            $text = (string) preg_replace('/'.preg_quote($part, '/').'/iu', '', $text);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text)) ?: null;
    }

    /** @return array{key: string, label: string}|null */
    public static function experienceBand(?int $years): ?array
    {
        return match (true) {
            $years === null => null,
            $years < 2 => ['key' => '0_1', 'label' => 'Under 2 years of experience'],
            $years < 5 => ['key' => '2_4', 'label' => '2 to 4 years of experience'],
            $years < 10 => ['key' => '5_9', 'label' => '5 to 9 years of experience'],
            default => ['key' => '10_plus', 'label' => '10 or more years of experience'],
        };
    }
}

<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use App\Services\Pulse\Discovery\Support\SubjectTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Turns signals into Beats for one viewer with a fixed number of queries: subjects are loaded one query per
 * subject type, people and places once each, whatever the number of beats.
 */
class BeatBuilder
{
    public function __construct(private readonly PlaceRepository $places) {}

    /**
     * @param  Collection<int, PulseSignal>  $signals  in the order they should appear
     * @param  array<int, array{id: ?int, score: float, reasons: array<int, mixed>}>  $matches  signal id => stored match
     * @param  array<int, true>  $saved  signal ids the viewer saved
     * @return Collection<int, Beat>
     */
    public function build(int $viewerId, Collection $signals, SignalPack $pack, array $matches = [], array $saved = []): Collection
    {
        if ($signals->isEmpty()) {
            return collect();
        }

        $subjects = $this->loadSubjects($signals);

        $personIds = $signals->filter(fn ($s) => $s->entity_id === null && $s->owner_id !== null && ! $s->anonymous)->pluck('owner_id')->unique()->values()->all();
        $people = $personIds === [] ? collect() : DB::table('users')->whereIn('id', $personIds)->get(['id', 'name', 'profile_photo_path'])->keyBy('id');

        return $signals->map(function (PulseSignal $s) use ($viewerId, $subjects, $people, $pack, $matches, $saved) {
            $type = SubjectTypes::normalise((string) $s->subject_type);
            $subject = $subjects[$type][(int) $s->subject_id] ?? null;
            $match = $matches[$s->id] ?? null;
            $anonymous = (bool) $s->anonymous || $s->kind === 'talent' && $this->isAnonymousProfile($s, $pack);

            return $anonymous
                ? $this->anonymousBeat($s, $pack, $match, isset($saved[$s->id]))
                : $this->openBeat($viewerId, $s, $type, $subject, $pack, $people, $match, isset($saved[$s->id]));
        })->values();
    }

    private function openBeat(int $viewerId, PulseSignal $s, string $type, ?Model $subject, SignalPack $pack, Collection $people, ?array $match, bool $saved): Beat
    {
        $entity = $s->entity_id ? ($pack->entities[(int) $s->entity_id] ?? null) : null;
        $person = $s->owner_id ? $people->get($s->owner_id) : null;

        $from = [
            'name' => $entity->name ?? $person->name ?? null,
            'avatar' => $this->avatarUrl($person->profile_photo_path ?? null),
            'anonymous' => false,
            'entity' => $entity ? ['id' => (int) $entity->id, 'slug' => $entity->slug, 'name' => $entity->name, 'logo_media_id' => $entity->logo_media_id] : null,
            'ladder' => $entity ? $this->ladder($entity) : null,
        ];

        return new Beat(
            signal: $s,
            subject: $subject,
            kind: $s->kind,
            title: $s->title,
            excerpt: $s->excerpt,
            from: $from,
            place: $this->placeArray($pack->place($s->place_id)),
            reasons: $this->reasons($match),
            score: (float) ($match['score'] ?? 0),
            published_at: $s->published_at,
            can_knock: $this->canKnock($viewerId, $s, $entity),
            saved: $saved,
            match_id: $match['id'] ?? null,
            card: null,
            url: $this->url($type, $subject, $entity),
            topics: array_values((array) $s->topics),
        );
    }

    /** What an employer may see of an anonymous professional, and nothing else. */
    private function anonymousBeat(PulseSignal $s, SignalPack $pack, ?array $match, bool $saved): Beat
    {
        $profile = $pack->profileOf($s);
        $band = Taxonomy::experienceBand(isset($profile->experience_years) ? (int) $profile->experience_years : null);
        $place = $pack->place($s->place_id);
        $region = $place ? ($place->level === 'country' ? $place->name : ($place->region ?? $place->name)) : null;

        // A sanitised copy: no owner, no relations, so nothing downstream can reach the person.
        $safe = new PulseSignal($s->only(['kind', 'subject_type', 'subject_id', 'title', 'excerpt', 'topics', 'skills', 'reach', 'is_remote', 'anonymous', 'quality', 'status', 'published_at', 'expires_at']));
        $safe->id = $s->id;
        $safe->exists = true;
        $safe->owner_id = null;
        $safe->entity_id = null;
        $safe->place_id = null;

        return new Beat(
            signal: $safe,
            subject: null,
            kind: $s->kind,
            title: $s->title,
            excerpt: $s->excerpt,
            from: ['name' => null, 'avatar' => null, 'anonymous' => true, 'entity' => null, 'ladder' => null],
            place: $region ? ['id' => null, 'slug' => null, 'name' => $region, 'label' => $region, 'level' => 'region'] : null,
            reasons: $this->reasons($match),
            score: (float) ($match['score'] ?? 0),
            published_at: $s->published_at,
            can_knock: ['allowed' => true, 'mode' => 'request', 'why' => 'You can invite them. They choose whether to say who they are.'],
            saved: $saved,
            match_id: $match['id'] ?? null,
            card: [
                'headline' => $s->title,
                'skills' => array_map(fn ($sl) => Taxonomy::label((string) $sl), array_slice(array_values((array) $s->skills), 0, 8)),
                'experience_band' => $band['label'] ?? null,
                'region' => $region,
            ],
            url: null,
            topics: array_values((array) $s->topics),
        );
    }

    private function isAnonymousProfile(PulseSignal $s, SignalPack $pack): bool
    {
        $profile = $pack->profileOf($s);

        return $profile === null || $profile->discoverability !== 'open';
    }

    /** @return list<array{code: string, text: string, weight: float, ref: ?string}> */
    private function reasons(?array $match): array
    {
        $out = [];

        foreach ((array) ($match['reasons'] ?? []) as $r) {
            if (is_array($r) && isset($r['code'])) {
                $out[] = ['code' => (string) $r['code'], 'text' => (string) ($r['text'] ?? ''), 'weight' => (float) ($r['weight'] ?? 0), 'ref' => isset($r['ref']) ? (string) $r['ref'] : null];
            }
        }

        return $out;
    }

    /**
     * A cheap pre-check from the page's own contact policy. The real gate (knock budget, blocks, age, context) is
     * ContactGate::can at the moment of the knock; the UI must call it there.
     *
     * @return array{allowed: bool, mode: string, why: ?string}
     */
    private function canKnock(int $viewerId, PulseSignal $s, ?object $entity): array
    {
        if ($s->owner_id !== null && (int) $s->owner_id === $viewerId) {
            return ['allowed' => false, 'mode' => 'blocked', 'why' => 'This is yours.'];
        }

        if ($entity !== null && $entity->contact_policy === 'nobody') {
            return ['allowed' => false, 'mode' => 'blocked', 'why' => 'This page is not taking new contact.'];
        }

        if ($entity !== null && $entity->contact_policy === 'anyone') {
            return ['allowed' => true, 'mode' => 'direct', 'why' => null];
        }

        return ['allowed' => true, 'mode' => 'request', 'why' => 'Your first message goes as a request they can accept.'];
    }

    /** @return array{status: string, label: ?string}|null */
    private function ladder(object $entity): array
    {
        $status = (string) $entity->verification_status;
        $checked = $entity->verification_source === 'infodot' && ! empty($entity->verified_at);

        $label = match (true) {
            in_array($status, ['verified', 'infodot_verified'], true) && $checked => 'Checked through InfoDot',
            $status === 'infodot_member' => 'InfoDot member',
            $status === 'claimed', ! empty($entity->registration_number) => 'Claims a registration number. Not checked.',
            default => null,
        };

        return ['status' => $status, 'label' => $label];
    }

    private function placeArray(?\App\Models\PulsePlace $place): ?array
    {
        return $place ? ['id' => $place->id, 'slug' => $place->slug, 'name' => $place->name, 'label' => $this->places->label($place), 'level' => $place->level] : null;
    }

    private function avatarUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        try {
            return Storage::disk((string) config('jetstream.profile_photo_disk', 'public'))->url($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function url(string $type, ?Model $subject, ?object $entity): ?string
    {
        try {
            return match (true) {
                $type === 'offer' && $subject instanceof PulseOffer && $entity && Route::has('offers.show') => route('offers.show', ['entity' => $entity->slug, 'offer' => $subject->slug]),
                $type === 'role' && $subject instanceof PulseRole && $entity && Route::has('roles.show') => route('roles.show', ['entity' => $entity->slug, 'role' => $subject->id]),
                $type === 'need' && $subject instanceof PulseNeed && Route::has('needs.show') => route('needs.show', ['need' => $subject->id]),
                $type === 'entity' && $entity && Route::has('entities.show') => route('entities.show', ['entity' => $entity->slug]),
                $type === 'post' && $subject instanceof PulsePost && Route::has('posts.show') => route('posts.show', ['post' => $subject->id]),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One query per subject type.
     *
     * @param  Collection<int, PulseSignal>  $signals
     * @return array<string, array<int, Model>>
     */
    private function loadSubjects(Collection $signals): array
    {
        $out = [];
        $classes = [
            'offer' => PulseOffer::class, 'need' => PulseNeed::class, 'role' => PulseRole::class,
            'post' => PulsePost::class, 'entity' => PulseEntity::class, 'profile' => PulseProfile::class,
        ];

        foreach ($signals->groupBy(fn ($s) => SubjectTypes::normalise((string) $s->subject_type)) as $type => $group) {
            if (! isset($classes[$type]) || $group->contains(fn ($s) => $s->anonymous)) {
                // Anonymous subjects are never loaded: nothing downstream may hold a model that names the person.
                $group = $group->reject(fn ($s) => $s->anonymous);
            }

            if (! isset($classes[$type]) || $group->isEmpty()) {
                continue;
            }

            $out[$type] = $classes[$type]::query()->whereIn('id', $group->pluck('subject_id')->unique()->all())->get()->keyBy('id')->all();
        }

        return $out;
    }
}

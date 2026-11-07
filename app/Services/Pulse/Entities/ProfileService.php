<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseMedia;
use App\Models\PulsePlace;
use App\Models\PulseProfile;
use App\Models\PulseSignal;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A person's professional profile, and the one place that decides what other people may see of it.
 *
 * Discoverability: off (not found by employers), anonymous (found by skills, experience band and region only; the
 * name, email, photo, user id and exact place are never shown to anyone but the person, including moderators in
 * ordinary views), open (found with name and photo). Anonymous or open requires the 18+ confirmation, a headline
 * and at least one skill. Nobody under 18 is ever discoverable.
 *
 * Public talent text: the headline and bio are checked by the ContentGate once the person is discoverable. A
 * "hold" verdict hides the talent signal until a moderator looks; with no rules installed the text is accepted,
 * because it is scrubbed of emails, phone numbers and links before it is indexed (see SignalIndexer).
 */
class ProfileService
{
    public function __construct(private readonly ContentGate $gate) {}

    public function profileFor(User $user): PulseProfile
    {
        return PulseProfile::firstOrCreate(['user_id' => $user->id], ['role' => 'customer', 'community_points' => 0]);
    }

    /**
     * The platform role is never writable here.
     *
     * @param  array<string, mixed>  $data  headline, bio, skills, kind, place_id, reach, show_place, experience_years,
     *                                      languages, interests, achievements, work_history, contact_policy, open_to
     */
    public function update(User $by, array $data): PulseProfile
    {
        $profile = $this->profileFor($by);
        $clean = $this->validated($data);

        $profile->fill($clean);
        $changed = array_keys($profile->getDirty());

        if ($profile->isDirty(['headline', 'bio', 'achievements', 'skills']) || $profile->discoverability !== 'off') {
            $profile->moderation = $this->moderationFor($by, $profile);
        }

        $profile->save();
        Audit::record($by, 'profile.update', $profile, ['fields' => $changed]);

        return $profile->refresh();
    }

    public function setDiscoverability(User $by, string $mode): PulseProfile
    {
        if (! in_array($mode, PulseProfile::DISCOVERABILITY, true)) {
            throw ValidationException::withMessages(['discoverability' => 'Choose off, anonymous or open.']);
        }

        $profile = $this->profileFor($by);

        if ($mode !== 'off') {
            if (! $profile->isAdultConfirmed()) {
                throw ValidationException::withMessages(['discoverability' => 'Confirm that you are '.config('pulse.age_min', 18).' or over first.']);
            }

            if (blank($profile->headline) || count(array_filter((array) $profile->skills)) < 1) {
                throw ValidationException::withMessages(['discoverability' => 'Add a headline and at least one skill so employers have something to match on.']);
            }
        }

        $profile->discoverability = $mode;
        $profile->moderation = $this->moderationFor($by, $profile);
        $profile->save();

        Audit::record($by, 'profile.discoverability', $profile, ['mode' => $mode]);

        return $profile->refresh();
    }

    /** @param list<string> $values work, freelance, collab, mentoring, investing, suppliers, learning */
    public function setOpenTo(User $by, array $values): PulseProfile
    {
        $values = array_values(array_unique(array_filter($values, 'is_string')));

        if (array_diff($values, PulseProfile::OPEN_TO)) {
            throw ValidationException::withMessages(['open_to' => 'Choose from work, freelance, collaborators, mentoring, investing, suppliers and learning.']);
        }

        $profile = $this->profileFor($by);
        $profile->update(['open_to' => $values]);
        Audit::record($by, 'profile.open_to', $profile, ['open_to' => $values]);

        return $profile->refresh();
    }

    public function setContactPolicy(User $by, string $policy): PulseProfile
    {
        if (! in_array($policy, PulseProfile::CONTACT_POLICIES, true)) {
            throw ValidationException::withMessages(['contact_policy' => 'Choose who may contact you.']);
        }

        $profile = $this->profileFor($by);
        $profile->update(['contact_policy' => $policy]);
        Audit::record($by, 'profile.contact_policy', $profile, ['policy' => $policy]);

        return $profile->refresh();
    }

    /** The person says they are 18 or over. Stored with the time; never inferred. */
    public function confirmAge(User $by): PulseProfile
    {
        $profile = $this->profileFor($by);

        if ($profile->age_confirmed_at === null) {
            $profile->update(['age_confirmed_at' => now()]);
            Audit::record($by, 'profile.age_confirmed', $profile);
        }

        return $profile->refresh();
    }

    /** @param int|null $mediaId a PulseMedia of purpose "avatar" the person uploaded; null removes it */
    public function setAvatar(User $by, ?int $mediaId): PulseProfile
    {
        $profile = $this->profileFor($by);

        if ($mediaId !== null && ! PulseMedia::query()->whereKey($mediaId)->where('user_id', $by->id)->where('purpose', 'avatar')->exists()) {
            throw ValidationException::withMessages(['avatar' => 'That image is not yours.']);
        }

        $profile->update(['avatar_media_id' => $mediaId]);

        return $profile->refresh();
    }

    /** Onboarding is done when the age is confirmed (now or before). */
    public function completeOnboarding(User $by, bool $ageConfirmed = false): PulseProfile
    {
        $profile = $this->profileFor($by);

        if ($ageConfirmed) {
            $profile = $this->confirmAge($by);
        }

        if (! $profile->isAdultConfirmed()) {
            throw ValidationException::withMessages(['age' => 'Pulse is for people who are '.config('pulse.age_min', 18).' or over. Confirm to continue.']);
        }

        if ($profile->onboarded_at === null) {
            $profile->update(['onboarded_at' => now()]);
            Audit::record($by, 'profile.onboarded', $profile);
        }

        return $profile->refresh();
    }

    /**
     * What $viewer may see of this profile.
     *
     * - the person themselves: everything, with `is_self` true;
     * - anyone (signed in or out, moderators included) viewing an ANONYMOUS profile, and any signed-out visitor
     *   viewing any profile: headline, skills, experience band, region, open to, languages. No name, email, photo,
     *   user id, handle or exact place. `signal_id` is the handle for inviting them.
     * - a signed-in viewer of an open or non-discoverable profile: also name, photo, bio, place and the user id.
     *
     * @return array<string, mixed>
     */
    public function card(PulseProfile $p, ?User $viewer): array
    {
        $p->loadMissing(['user', 'place']);

        $self = $viewer !== null && $viewer->id === $p->user_id;
        $anonymous = $p->discoverability === 'anonymous' && ! $self;
        $limited = $anonymous || $viewer === null;

        $band = SignalIndexer::experienceBand($p->experience_years);
        $discoverable = $p->isDiscoverable();

        $card = [
            'anonymous' => $anonymous,
            'kind' => $p->kind,
            'headline' => $limited ? $this->publicLine($p->headline, $p) : $p->headline,
            'skills' => array_values((array) $p->skills),
            'experience_band' => $band,
            'region' => $this->regionName($p, $limited),
            'open_to' => ($discoverable || $self) ? array_values((array) $p->open_to) : [],
            'languages' => array_values((array) $p->languages),
            'contact_policy' => $p->contact_policy,
            'signal_id' => $discoverable ? PulseSignal::forSubject('profile', (int) $p->id)->value('id') : null,
            'is_self' => $self,
        ];

        if ($limited) {
            return $card;
        }

        $user = $p->user;

        return $card + [
            'user_id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $p->avatar_media_id ? app(MediaService::class)->url($p->avatar_media_id, 'thumb') : null,
            'bio' => $p->bio,
            'place' => $p->show_place || $self ? $p->place?->name : null,
            'interests' => array_values((array) $p->interests),
            'achievements' => array_values((array) $p->achievements),
            'work_history' => ($self || $p->discoverability === 'open') ? $this->workHistoryFor((array) $p->work_history, $self) : [],
            'discoverability' => $self ? $p->discoverability : null,
        ];
    }

    /**
     * The hide flag is a private setting. Only the person sees it; everyone else gets the job without it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function workHistoryFor(array $rows, bool $self): array
    {
        return array_values(array_map(function ($row) use ($self) {
            $row = (array) $row;

            if (! $self) {
                unset($row['hide_from_employer']);
            }

            return $row;
        }, $rows));
    }

    // ---- internals ------------------------------------------------------------------------------------------

    /** For an anonymous card, the headline is cleaned the same way the signal title is. */
    private function publicLine(?string $line, PulseProfile $p): ?string
    {
        $signalTitle = PulseSignal::forSubject('profile', (int) $p->id)->value('title');

        return $signalTitle ?? Sanitizer::withoutContactDetails($line === null ? null : $this->withoutName($line, $p->user));
    }

    private function withoutName(string $text, ?User $user): string
    {
        if ($user === null) {
            return $text;
        }

        foreach (array_filter(preg_split('/[\s.\-_]+/u', $user->name.' '.strstr($user->email, '@', true)) ?: [], fn ($x) => mb_strlen($x) >= 3) as $part) {
            $text = (string) preg_replace('/'.preg_quote($part, '/').'/iu', '', $text);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Region name; for a limited view never anything finer than a region. */
    private function regionName(PulseProfile $p, bool $limited): ?string
    {
        if (! $p->place) {
            return null;
        }

        if (! $limited) {
            return $p->show_place ? ($this->region($p->place)?->name ?? $p->place->name) : null;
        }

        return $p->show_place || $p->discoverability === 'anonymous' ? $this->region($p->place)?->name : null;
    }

    private function region(PulsePlace $place): ?PulsePlace
    {
        return SignalIndexer::regionOf($place);
    }

    private function moderationFor(User $by, PulseProfile $profile): string
    {
        if ($profile->discoverability === 'off') {
            return 'approved';
        }

        $text = implode("\n", array_filter([$profile->headline, $profile->bio, implode(' ', (array) $profile->achievements)]));

        // pending (no rules installed) is accepted for talent text, see the class comment; hold is not.
        return $this->gate->decide($by, $text, ['kind' => 'profile'])['moderation'] === 'held' ? 'held' : 'approved';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $clean = Validator::make($data, [
            'headline' => ['sometimes', 'nullable', 'string', 'max:300'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'skills' => ['sometimes', 'nullable', 'array', 'max:30'],
            'kind' => ['sometimes', Rule::in(['individual', 'professional'])],
            'place_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pulse_places', 'id')],
            'reach' => ['sometimes', Rule::in(['city', 'region', 'country', 'remote'])],
            'show_place' => ['sometimes', 'boolean'],
            'experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'languages' => ['sometimes', 'nullable', 'array', 'max:15'],
            'interests' => ['sometimes', 'nullable', 'array', 'max:30'],
            'achievements' => ['sometimes', 'nullable', 'array', 'max:10'],
            'work_history' => ['sometimes', 'nullable', 'array', 'max:8'],
            'work_history.*.title' => ['required_with:work_history', 'string', 'max:120'],
            'work_history.*.org' => ['nullable', 'string', 'max:120'],
            'work_history.*.employer' => ['nullable', 'string', 'max:120'],
            'work_history.*.hide_from_employer' => ['nullable', 'boolean'],
            'work_history.*.from_year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year)],
            'work_history.*.to_year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year)],
            'work_history.*.current' => ['nullable', 'boolean'],
            'contact_policy' => ['sometimes', Rule::in(PulseProfile::CONTACT_POLICIES)],
            'open_to' => ['sometimes', 'nullable', 'array'],
            'open_to.*' => [Rule::in(PulseProfile::OPEN_TO)],
        ])->validate();

        foreach (['headline' => 120] as $key => $max) {
            if (array_key_exists($key, $clean)) {
                $clean[$key] = Sanitizer::line($clean[$key], $max);
            }
        }

        if (array_key_exists('bio', $clean)) {
            $clean['bio'] = Sanitizer::text($clean['bio'], 1500);
        }

        foreach (['skills' => [12, 40], 'languages' => [10, 40], 'interests' => [15, 40], 'achievements' => [10, 160]] as $key => [$items, $len]) {
            if (array_key_exists($key, $clean)) {
                $clean[$key] = Sanitizer::list($clean[$key], $items, $len);
            }
        }

        if (array_key_exists('open_to', $clean)) {
            $clean['open_to'] = array_values(array_unique((array) $clean['open_to']));
        }

        if (array_key_exists('work_history', $clean)) {
            // `employer` is the canonical name the Matcher compares against employer pages; `org` is kept equal to it
            // so older readers still work. A job can only be hidden from an employer that is named.
            $clean['work_history'] = array_values(array_map(function (array $row) {
                $employer = Sanitizer::line($row['employer'] ?? ($row['org'] ?? null), 80);

                return [
                    'title' => Sanitizer::line($row['title'] ?? null, 80),
                    'employer' => $employer,
                    'org' => $employer,
                    'hide_from_employer' => (bool) ($row['hide_from_employer'] ?? false) && filled($employer),
                    'from_year' => $row['from_year'] ?? null,
                    'to_year' => $row['to_year'] ?? null,
                    'current' => (bool) ($row['current'] ?? false),
                ];
            }, (array) $clean['work_history']));
        }

        return $clean;
    }
}

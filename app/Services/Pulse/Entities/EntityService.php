<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\PulseMedia;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Business pages (entities) and the people on them.
 *
 * Roles: owner, admin, editor, responder. The last owner is never removed or demoted. Public text is passed through
 * the Sanitizer and the ContentGate; a page whose text is not approved is visible to its members only.
 */
class EntityService
{
    public function __construct(private readonly ContentGate $gate) {}

    /**
     * @param  array<string, mixed>  $data  name, tagline, about, is_seller, is_employer, industry, place_id, service_area,
     *                                      website, public_email, public_phone, culture, team_size, founded_year,
     *                                      contact_policy, hiring_signal, hiring_skills, logo_media_id, cover_media_id
     */
    public function create(User $by, array $data): PulseEntity
    {
        Gate::forUser($by)->authorize('create', PulseEntity::class);

        $max = (int) config('pulse.entities.max_per_user', 5);

        if (PulseEntity::query()->where('created_by', $by->id)->count() >= $max) {
            throw ValidationException::withMessages(['name' => "You can run up to {$max} business pages."]);
        }

        $clean = $this->validated($data, creating: true);
        $text = $this->publicText($clean);
        $decision = $this->gate->decide($by, $text, ['kind' => 'entity']);

        $entity = DB::transaction(function () use ($by, $clean, $decision, $data) {
            $entity = new PulseEntity($this->fields($clean));
            $entity->forceFill([
                'slug' => Slugger::unique($clean['name'], fn (string $s) => PulseEntity::withTrashed()->where('slug', $s)->exists()),
                'created_by' => $by->id,
                'status' => ($data['draft'] ?? false) ? 'draft' : 'live',
                ...$this->gate->stamp($decision),
                'verification_status' => 'unverified',
                'hiring_signal_until' => ($clean['hiring_signal'] ?? 'none') !== 'none' ? now()->addDays((int) config('pulse.entities.hiring_signal_days', 60)) : null,
                'claims' => $this->claimsFor($clean, []),
            ])->save();

            PulseEntityMember::create(['entity_id' => $entity->id, 'user_id' => $by->id, 'role' => 'owner']);

            return $entity;
        });

        $this->applyImages($entity, $clean);
        app(VerificationService::class)->recompute($entity->refresh());
        Audit::record($by, 'entity.create', $entity, ['moderation' => $entity->moderation, 'source' => $decision['source'], 'draft' => $entity->status === 'draft']);

        return $entity->refresh();
    }

    /**
     * A draft goes on the floor: its text is checked again at this moment, exactly as an offer's is. A page that is
     * archived (taken off) comes back through restore(), not here.
     */
    public function publish(User $by, PulseEntity $e): PulseEntity
    {
        Gate::forUser($by)->authorize('archive', $e);

        if ($e->status !== 'draft') {
            throw ValidationException::withMessages(['status' => $e->status === 'live' ? 'This page is already on the floor.' : 'Only a draft is published here.']);
        }

        $decision = $this->gate->decide($by, $this->publicText($e->only(['name', 'tagline', 'about'])), ['kind' => 'entity']);

        $e->forceFill(['status' => 'live'] + $this->gate->stamp($decision))->save();
        Audit::record($by, 'entity.publish', $e, ['moderation' => $e->moderation, 'source' => $decision['source']]);

        return $e->refresh();
    }

    /** @param array<string, mixed> $data  (pass `draft => true` to create it off the floor) */
    public function update(User $by, PulseEntity $e, array $data): PulseEntity
    {
        Gate::forUser($by)->authorize('update', $e);

        $clean = $this->validated($data, creating: false, entity: $e);
        $before = $e->only(['name', 'tagline', 'about', 'website', 'public_phone']);

        $e->fill($this->fields($clean));
        $e->claims = $this->claimsFor($clean, (array) $e->claims, $e);

        if (! $e->is_employer) {
            $e->hiring_signal = 'none';
        }

        // A hiring signal lapses unless it is renewed: setting it starts the clock, switching it off stops it.
        if (array_key_exists('hiring_signal', $clean) || ! $e->is_employer) {
            $e->hiring_signal_until = $e->hiring_signal === 'none' ? null : now()->addDays((int) config('pulse.entities.hiring_signal_days', 60));
        }

        $textChanged = $e->isDirty(['name', 'tagline', 'about']);

        if ($textChanged) {
            $decision = $this->gate->decide($by, $this->publicText($e->only(['name', 'tagline', 'about'])), ['kind' => 'entity']);
            $e->forceFill($this->gate->stamp($decision));
        }

        $changed = array_keys($e->getDirty());
        $e->save();
        $this->applyImages($e, $clean);

        if (isset($clean['website']) || isset($clean['public_phone'])) {
            app(VerificationService::class)->recompute($e->refresh());
        }

        Audit::record($by, 'entity.update', $e, ['fields' => array_values(array_diff($changed, ['updated_at'])), 'was' => $before['name'] !== $e->name ? ['name' => $before['name']] : []]);

        return $e->refresh();
    }

    public function addMember(User $by, PulseEntity $e, User $u, string $role): void
    {
        if (! in_array($role, PulseEntity::MEMBER_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Choose owner, admin, editor or responder.']);
        }

        Gate::forUser($by)->authorize('manageMembers', [$e, $role]);

        $existing = PulseEntityMember::query()->where('entity_id', $e->id)->where('user_id', $u->id)->first();

        if ($existing) {
            // Changing someone who already holds a role needs the power to touch the role they hold now as well.
            Gate::forUser($by)->authorize('manageMembers', [$e, $existing->role]);

            if ($existing->role === 'owner' && $role !== 'owner') {
                $this->guardLastOwner($e, $u);
            }

            if ($existing->role !== $role) {
                $existing->update(['role' => $role]);
                Audit::record($by, 'entity.member.role', $e, ['user_id' => $u->id, 'from' => $existing->getOriginal('role'), 'to' => $role]);
            }

            return;
        }

        PulseEntityMember::create(['entity_id' => $e->id, 'user_id' => $u->id, 'role' => $role]);
        Audit::record($by, 'entity.member.add', $e, ['user_id' => $u->id, 'role' => $role]);
    }

    /** A member may always leave (except as the last owner); removing someone else needs the power over their role. */
    public function removeMember(User $by, PulseEntity $e, User $u): void
    {
        $member = PulseEntityMember::query()->where('entity_id', $e->id)->where('user_id', $u->id)->first();

        if (! $member) {
            return;
        }

        if ($by->id !== $u->id) {
            Gate::forUser($by)->authorize('manageMembers', [$e, $member->role]);
        } elseif ($e->memberRole($by) === null) {
            throw new AuthorizationException;
        }

        if ($member->role === 'owner') {
            $this->guardLastOwner($e, $u);
        }

        $member->delete();
        Audit::record($by, 'entity.member.remove', $e, ['user_id' => $u->id, 'role' => $member->role]);
    }

    public function follow(User $u, PulseEntity $e): void
    {
        Gate::forUser($u)->authorize('view', $e);

        DB::table('pulse_entity_followers')->insertOrIgnore([
            'entity_id' => $e->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->recountFollowers($e);
    }

    public function unfollow(User $u, PulseEntity $e): void
    {
        DB::table('pulse_entity_followers')->where('entity_id', $e->id)->where('user_id', $u->id)->delete();
        $this->recountFollowers($e);
    }

    /** Another full period for a hiring signal that is on. Owners and admins, like any change to the signal. */
    public function renewHiringSignal(User $by, PulseEntity $e): PulseEntity
    {
        Gate::forUser($by)->authorize('update', $e);

        if (! $e->is_employer || $e->hiring_signal === 'none') {
            throw ValidationException::withMessages(['hiring_signal' => 'There is no hiring signal to renew. Turn one on first.']);
        }

        $e->forceFill(['hiring_signal_until' => now()->addDays((int) config('pulse.entities.hiring_signal_days', 60))])->save();
        Audit::record($by, 'entity.hiring_signal.renew', $e, ['until' => $e->hiring_signal_until?->toIso8601String()]);

        return $e->refresh();
    }

    /** Takes the page and everything on it out of discovery. Members still see it; `restore` brings it back. */
    public function archive(User $by, PulseEntity $e): void
    {
        Gate::forUser($by)->authorize('archive', $e);

        $e->update(['status' => 'archived']);
        Audit::record($by, 'entity.archive', $e);
    }

    public function restore(User $by, PulseEntity $e): void
    {
        Gate::forUser($by)->authorize('archive', $e);

        if ($e->status === 'draft') {
            $this->publish($by, $e);

            return;
        }

        if ($e->status === 'suspended') {
            throw ValidationException::withMessages(['status' => 'A suspended page can only be reinstated by a moderator.']);
        }

        $e->update(['status' => 'live']);
        Audit::record($by, 'entity.restore', $e);
    }

    /** Real counts only. Response figures come from conversations (agent D3's tables) and are null when there are none. */
    public function stats(PulseEntity $e): array
    {
        $stats = [
            'offers' => $e->offers()->live()->count(),
            'roles' => $e->roles()->live()->count(),
            'followers' => (int) $e->followers_count,
            'conversations' => 0,
            'response_rate' => null,
            'median_response_seconds' => null,
            'confirmed_outcomes' => 0,
        ];

        if (! Schema::hasTable('pulse_conversations') || ! Schema::hasColumn('pulse_conversations', 'entity_id')) {
            return $stats;
        }

        $base = DB::table('pulse_conversations')->where('entity_id', $e->id)->whereNotIn('status', ['spam']);
        $total = (clone $base)->count();
        $stats['conversations'] = $total;
        $stats['confirmed_outcomes'] = (clone $base)->whereNotNull('outcome_initiator_at')->whereNotNull('outcome_recipient_at')->where('outcome', '!=', 'none')->count();

        if ($total === 0) {
            return $stats;
        }

        $answered = (clone $base)->whereNotNull('first_response_at');
        $stats['response_rate'] = round((clone $answered)->count() / $total, 3);

        $seconds = (clone $answered)->orderByDesc('id')->limit(500)->get(['created_at', 'first_response_at'])
            ->map(fn ($c) => max(0, Carbon::parse($c->first_response_at)->diffInSeconds(Carbon::parse($c->created_at), true)))
            ->sort()->values();

        if ($seconds->isNotEmpty()) {
            $mid = intdiv($seconds->count(), 2);
            $stats['median_response_seconds'] = (int) ($seconds->count() % 2 ? $seconds[$mid] : ($seconds[$mid - 1] + $seconds[$mid]) / 2);
        }

        return $stats;
    }

    // ---- internals ------------------------------------------------------------------------------------------

    private function guardLastOwner(PulseEntity $e, User $leaving): void
    {
        $others = PulseEntityMember::query()->where('entity_id', $e->id)->where('role', 'owner')->where('user_id', '!=', $leaving->id)->count();

        if ($others === 0) {
            throw ValidationException::withMessages(['member' => 'A business page always needs an owner. Make someone else an owner first.']);
        }
    }

    private function recountFollowers(PulseEntity $e): void
    {
        $count = DB::table('pulse_entity_followers')->where('entity_id', $e->id)->count();
        DB::table('pulse_entities')->where('id', $e->id)->update(['followers_count' => $count]);
        $e->followers_count = $count;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, bool $creating, ?PulseEntity $entity = null): array
    {
        $sometimes = $creating ? 'required' : 'sometimes';
        $year = (int) now()->year;

        $clean = Validator::make($data, [
            'name' => [$sometimes, 'string', 'min:2', 'max:140'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:300'],
            'about' => ['sometimes', 'nullable', 'string', 'max:6000'],
            'is_seller' => ['sometimes', 'boolean'],
            'is_employer' => ['sometimes', 'boolean'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:120'],
            'place_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pulse_places', 'id')],
            'service_area' => ['sometimes', 'nullable', 'array', 'max:20'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255', 'url:http,https'],
            'public_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'public_phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[0-9 +().\-]{6,40}$/'],
            'culture' => ['sometimes', 'nullable', 'array', 'max:20'],
            'team_size' => ['sometimes', 'nullable', Rule::in(['1', '2-10', '11-50', '51-200', '201-500', '501+'])],
            'founded_year' => ['sometimes', 'nullable', 'integer', 'min:1800', "max:{$year}"],
            'contact_policy' => ['sometimes', Rule::in(['anyone', 'context', 'requests', 'nobody'])],
            'hiring_signal' => ['sometimes', Rule::in(['none', 'building', 'active'])],
            'hiring_skills' => ['sometimes', 'nullable', 'array', 'max:15'],
            'logo_media_id' => ['sometimes', 'nullable', 'integer'],
            'cover_media_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();

        $isSeller = (bool) ($clean['is_seller'] ?? $entity?->is_seller ?? ($creating && ! array_key_exists('is_employer', $clean)));
        $isEmployer = (bool) ($clean['is_employer'] ?? $entity?->is_employer ?? false);

        if (! $isSeller && ! $isEmployer) {
            throw ValidationException::withMessages(['is_seller' => 'A business page sells, hires, or both.']);
        }

        if (($clean['hiring_signal'] ?? 'none') !== 'none' && ! $isEmployer) {
            throw ValidationException::withMessages(['hiring_signal' => 'Turn on hiring for this page before signalling that you are hiring.']);
        }

        if ($creating) {
            $clean['is_seller'] = $isSeller;
            $clean['is_employer'] = $isEmployer;
        }

        return $this->sanitised($clean);
    }

    /**
     * @param  array<string, mixed>  $clean
     * @return array<string, mixed>
     */
    private function sanitised(array $clean): array
    {
        $out = $clean;
        $line = ['name' => 140, 'tagline' => 160, 'industry' => 80, 'public_phone' => 40];

        foreach ($line as $key => $max) {
            if (array_key_exists($key, $out)) {
                $out[$key] = Sanitizer::line($out[$key], $max);
            }
        }

        if (array_key_exists('about', $out)) {
            $out['about'] = Sanitizer::text($out['about'], 5000);
        }

        if (array_key_exists('service_area', $out)) {
            $out['service_area'] = Sanitizer::list($out['service_area'], 20, 60);
        }

        if (array_key_exists('hiring_skills', $out)) {
            $out['hiring_skills'] = Sanitizer::list($out['hiring_skills'], 15, 40);
        }

        if (array_key_exists('culture', $out)) {
            $out['culture'] = $this->culture((array) $out['culture']);
        }

        if (array_key_exists('website', $out)) {
            $out['website'] = $out['website'] === null ? null : mb_substr((string) $out['website'], 0, 255);
        }

        return $out;
    }

    /**
     * values, perks, work_mode: short strings and short lists of strings.
     *
     * @param  array<mixed>  $culture
     * @return array<string, mixed>
     */
    private function culture(array $culture): array
    {
        $out = [];

        foreach (['values', 'perks'] as $key) {
            if (isset($culture[$key])) {
                $out[$key] = Sanitizer::list((array) $culture[$key], 10, 60);
            }
        }

        if (isset($culture['work_mode']) && in_array($culture['work_mode'], ['onsite', 'hybrid', 'remote'], true)) {
            $out['work_mode'] = $culture['work_mode'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $clean
     * @return array<string, mixed>
     */
    private function fields(array $clean): array
    {
        return array_diff_key($clean, array_flip(['logo_media_id', 'cover_media_id']));
    }

    /** @param array<string, mixed> $clean */
    private function publicText(array $clean): string
    {
        return implode("\n", array_filter([$clean['name'] ?? null, $clean['tagline'] ?? null, $clean['about'] ?? null]));
    }

    /**
     * What the business says about itself, with when it said it. A claim is never proof.
     *
     * @param  array<string, mixed>  $clean
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private function claimsFor(array $clean, array $claims, ?PulseEntity $entity = null): array
    {
        foreach (['website', 'public_phone' => 'phone'] as $field => $claim) {
            $field = is_int($field) ? $claim : $field;

            if (! array_key_exists($field, $clean)) {
                continue;
            }

            $value = $clean[$field];

            if ($value === null) {
                unset($claims[$claim]);
            } elseif (($claims[$claim]['value'] ?? null) !== $value) {
                $claims[$claim] = ['value' => $value, 'at' => now()->toIso8601String()];
            }
        }

        return $claims ?: [];
    }

    /** Logo and cover must be this page's own images of the right purpose. */
    private function applyImages(PulseEntity $e, array $clean): void
    {
        $changes = [];

        foreach (['logo' => 'logo_media_id', 'cover' => 'cover_media_id'] as $purpose => $column) {
            if (! array_key_exists($column, $clean)) {
                continue;
            }

            $id = $clean[$column];

            if ($id !== null) {
                $ok = PulseMedia::query()->whereKey($id)->where('purpose', $purpose)->where('entity_id', $e->id)->exists();

                if (! $ok) {
                    throw ValidationException::withMessages([$column => 'That image does not belong to this page.']);
                }
            }

            $changes[$column] = $id;
        }

        if ($changes) {
            $e->forceFill($changes)->save();
        }
    }
}

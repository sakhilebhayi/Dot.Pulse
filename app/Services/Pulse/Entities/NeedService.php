<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A person's "looking for": something to buy, a supplier, a collaborator, an investor, a mentor, an introduction.
 * A need goes live when created (after the ContentGate) and ends on its own date, 30 days by default.
 */
class NeedService
{
    public function __construct(private readonly ContentGate $gate) {}

    /** @param array<string, mixed> $data */
    public function create(User $by, array $data): PulseNeed
    {
        AnonymousGuard::assertNeedAllowed($by);

        $max = (int) config('pulse.entities.max_open_needs', 10);

        if (PulseNeed::query()->where('user_id', $by->id)->where('status', 'open')->count() >= $max) {
            throw ValidationException::withMessages(['title' => "You can have {$max} open needs at a time. Close one to add another."]);
        }

        $clean = $this->validated($data, $by, creating: true);
        $decision = $this->gate->decide($by, $this->text($clean), ['kind' => 'need']);

        $need = new PulseNeed($clean);
        $need->forceFill([
            'user_id' => $by->id,
            'status' => 'open',
            ...$this->gate->stamp($decision),
            'published_at' => now(),
            'expires_at' => $clean['expires_at'] ?? now()->addDays((int) config('pulse.entities.need_ttl_days', 30)),
        ])->save();

        Audit::record($by, 'need.create', $need, ['moderation' => $need->moderation, 'source' => $decision['source']]);

        return $need->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(User $by, PulseNeed $n, array $data): PulseNeed
    {
        Gate::forUser($by)->authorize('update', $n);
        $this->guardEditable($n);

        $n->fill($this->validated($data, $by, creating: false, need: $n));
        $changed = array_keys($n->getDirty());

        if ($n->isDirty(['title', 'details', 'tags', 'budget_text'])) {
            $n->forceFill($this->gate->stamp($this->gate->decide($by, $this->text($n->only(['title', 'details', 'tags', 'budget_text'])), ['kind' => 'need'])));
        }

        $n->save();
        Audit::record($by, 'need.update', $n, ['fields' => $changed]);

        return $n->refresh();
    }

    /** $fulfilled true when the need was met (through Pulse or elsewhere), false when it was simply dropped. */
    public function close(User $by, PulseNeed $n, bool $fulfilled = false): PulseNeed
    {
        Gate::forUser($by)->authorize('update', $n);

        if ($n->status === 'open') {
            $n->update(['status' => $fulfilled ? 'fulfilled' : 'closed']);
            Audit::record($by, 'need.close', $n, ['fulfilled' => $fulfilled]);
        }

        return $n->refresh();
    }

    /** Opens a closed or lapsed need for another period. */
    public function reopen(User $by, PulseNeed $n): PulseNeed
    {
        Gate::forUser($by)->authorize('update', $n);
        $this->guardEditable($n);
        AnonymousGuard::assertNeedAllowed($by);

        $max = (int) config('pulse.entities.max_open_needs', 10);

        if ($n->status !== 'open' && PulseNeed::query()->where('user_id', $by->id)->where('status', 'open')->count() >= $max) {
            throw ValidationException::withMessages(['title' => "You can have {$max} open needs at a time. Close one first."]);
        }

        $n->forceFill([
            'status' => 'open',
            'expires_at' => now()->addDays((int) config('pulse.entities.need_ttl_days', 30)),
        ])->save();

        Audit::record($by, 'need.reopen', $n);

        return $n->refresh();
    }

    public function delete(User $by, PulseNeed $n): void
    {
        Gate::forUser($by)->authorize('delete', $n);

        $n->delete();
        Audit::record($by, 'need.delete', $n);
    }

    private function guardEditable(PulseNeed $n): void
    {
        if ($n->moderation === 'removed' || $n->status === 'removed') {
            throw ValidationException::withMessages(['need' => 'This was removed by a moderator and cannot be changed.']);
        }
    }

    /** @param array<string, mixed> $need */
    private function text(array $need): string
    {
        return implode("\n", array_filter([$need['title'] ?? null, $need['details'] ?? null, $need['budget_text'] ?? null, implode(' ', (array) ($need['tags'] ?? []))]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, User $by, bool $creating, ?PulseNeed $need = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $clean = Validator::make($data, [
            'title' => [$req, 'string', 'min:3', 'max:140'],
            'details' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'kind' => ['sometimes', Rule::in(['need', 'supplier', 'collab', 'investor', 'mentor', 'intro'])],
            'tags' => ['sometimes', 'nullable', 'array', 'max:10'],
            'budget_text' => ['sometimes', 'nullable', 'string', 'max:100'],
            'needed_by' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'place_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pulse_places', 'id')],
            'reach' => ['sometimes', Rule::in(['local', 'region', 'national', 'global', 'city', 'country', 'remote'])],
            'visibility' => ['sometimes', Rule::in(['open', 'matched'])],
            'entity_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pulse_entities', 'id')],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now', 'before:+120 days'],
        ], [
            'title.required' => 'Say what you need in a line.',
            'title.min' => 'Say it in a few more words.',
            'title.max' => 'Keep it to 140 characters. Put the rest in details.',
            'needed_by.after_or_equal' => 'Pick today or a later day.',
            'expires_at.after' => 'Pick a day in the future.',
        ])->validate();

        if (! empty($clean['entity_id'])) {
            $entity = PulseEntity::find($clean['entity_id']);

            if (! $entity || $entity->memberRole($by) === null) {
                throw ValidationException::withMessages(['entity_id' => 'You can only post a need for a page you belong to.']);
            }
        }

        if (array_key_exists('title', $clean)) {
            $clean['title'] = Sanitizer::line($clean['title'], 140);
        }

        if (array_key_exists('details', $clean)) {
            $clean['details'] = Sanitizer::text($clean['details'], 4000);
        }

        if (array_key_exists('budget_text', $clean)) {
            $clean['budget_text'] = Sanitizer::line($clean['budget_text'], 60);
        }

        if (array_key_exists('tags', $clean)) {
            $clean['tags'] = Sanitizer::list($clean['tags'], 10, 30);
        }

        if (isset($clean['expires_at'])) {
            $clean['expires_at'] = Carbon::parse($clean['expires_at']);
        }

        unset($clean['publish']);

        return $clean;
    }
}

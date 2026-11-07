<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An employer's openings. Same life cycle as an offer: draft, publish, pause, close (or filled).
 * Matching never rejects anyone: a role only appears to people whose skills fit, and a human decides.
 */
class RoleService
{
    public function __construct(private readonly ContentGate $gate) {}

    /** @param array<string, mixed> $data  pass 'publish' => true to publish straight away */
    public function create(User $by, PulseEntity $e, array $data): PulseRole
    {
        Gate::forUser($by)->authorize('manageListings', $e);

        if (! $e->is_employer) {
            throw ValidationException::withMessages(['entity' => 'Turn on hiring for this page before adding roles.']);
        }

        $max = (int) config('pulse.entities.max_roles', 50);

        if ($e->roles()->whereIn('status', ['draft', 'live', 'paused'])->count() >= $max) {
            throw ValidationException::withMessages(['title' => "A page can hold up to {$max} open roles. Close some first."]);
        }

        $publish = (bool) ($data['publish'] ?? false);
        $clean = $this->validated($data, creating: true);

        $role = new PulseRole($clean);
        $role->forceFill([
            'entity_id' => $e->id,
            'created_by' => $by->id,
            'slug' => Slugger::unique($clean['title'], fn (string $s) => PulseRole::withTrashed()->where('entity_id', $e->id)->where('slug', $s)->exists(), 140),
            'status' => 'draft',
            'moderation' => 'pending',
        ])->save();

        Audit::record($by, 'role.create', $role, ['entity_id' => $e->id]);

        return $publish ? $this->publish($by, $role) : $role->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(User $by, PulseRole $r, array $data): PulseRole
    {
        Gate::forUser($by)->authorize('update', $r);
        $this->guardEditable($r);

        $r->fill($this->validated($data, creating: false, role: $r));
        $changed = array_keys($r->getDirty());

        if ($r->status === 'live' && $r->isDirty(['title', 'summary', 'description', 'pay_text'])) {
            $r->forceFill($this->gate->stamp($this->gate->decide($by, $this->text($r), ['kind' => 'role'])));
        }

        $r->save();
        Audit::record($by, 'role.update', $r, ['fields' => $changed]);

        return $r->refresh();
    }

    public function pause(User $by, PulseRole $r): PulseRole
    {
        Gate::forUser($by)->authorize('update', $r);
        $this->guardEditable($r);

        if ($r->status === 'live') {
            $r->update(['status' => 'paused']);
            Audit::record($by, 'role.pause', $r);
        }

        return $r->refresh();
    }

    public function publish(User $by, PulseRole $r): PulseRole
    {
        Gate::forUser($by)->authorize('publish', $r);
        $this->guardEditable($r);

        if (blank($r->summary) && blank($r->description)) {
            throw ValidationException::withMessages(['summary' => 'Describe the role in a sentence or two before publishing.']);
        }

        $decision = $this->gate->decide($by, $this->text($r), ['kind' => 'role']);

        $r->forceFill([
            'status' => 'live',
            ...$this->gate->stamp($decision),
            'published_at' => $r->published_at ?? now(),
        ])->save();

        Audit::record($by, 'role.publish', $r, ['moderation' => $r->moderation, 'source' => $decision['source']]);

        return $r->refresh();
    }

    /** $as is "closed" (stopped hiring) or "filled" (someone was hired). */
    public function close(User $by, PulseRole $r, string $as = 'closed'): PulseRole
    {
        Gate::forUser($by)->authorize('update', $r);

        if (! in_array($as, ['closed', 'filled'], true)) {
            throw ValidationException::withMessages(['status' => 'A role is closed or filled.']);
        }

        if ($r->status !== 'removed') {
            $r->update(['status' => $as]);
            Audit::record($by, 'role.close', $r, ['as' => $as]);
        }

        return $r->refresh();
    }

    public function delete(User $by, PulseRole $r): void
    {
        Gate::forUser($by)->authorize('delete', $r);

        $r->delete();
        Audit::record($by, 'role.delete', $r);
    }

    private function guardEditable(PulseRole $r): void
    {
        if ($r->moderation === 'removed' || $r->status === 'removed') {
            throw ValidationException::withMessages(['role' => 'This role was removed by a moderator and cannot be changed.']);
        }
    }

    private function text(PulseRole $r): string
    {
        return implode("\n", array_filter([$r->title, $r->summary, $r->description, $r->pay_text, implode(' ', (array) $r->skills)]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, bool $creating, ?PulseRole $role = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $clean = Validator::make($data, [
            'title' => [$req, 'string', 'min:3', 'max:140'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:400'],
            'description' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'employment_type' => ['sometimes', Rule::in(['full_time', 'part_time', 'contract', 'internship', 'freelance'])],
            'work_mode' => ['sometimes', Rule::in(['onsite', 'hybrid', 'remote'])],
            'seniority' => ['sometimes', 'nullable', Rule::in(['intern', 'junior', 'mid', 'senior', 'lead', 'executive'])],
            'place_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pulse_places', 'id')],
            'skills' => ['sometimes', 'nullable', 'array', 'max:15'],
            'pay_text' => ['sometimes', 'nullable', 'string', 'max:120'],
            'apply_mode' => ['sometimes', Rule::in(['on_pulse', 'external_url'])],
            'apply_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:http,https'],
            'closes_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ])->validate();

        foreach (['title' => 140, 'pay_text' => 80] as $key => $max) {
            if (array_key_exists($key, $clean)) {
                $clean[$key] = Sanitizer::line($clean[$key], $max);
            }
        }

        if (array_key_exists('summary', $clean)) {
            $clean['summary'] = Sanitizer::line($clean['summary'], 240);
        }

        if (array_key_exists('description', $clean)) {
            $clean['description'] = Sanitizer::text($clean['description'], 6000);
        }

        if (array_key_exists('skills', $clean)) {
            $clean['skills'] = Sanitizer::list($clean['skills'], 15, 40);
        }

        $mode = $clean['apply_mode'] ?? $role?->apply_mode ?? 'on_pulse';
        $url = array_key_exists('apply_url', $clean) ? $clean['apply_url'] : $role?->apply_url;

        if ($mode === 'external_url' && blank($url)) {
            throw ValidationException::withMessages(['apply_url' => 'Give the address people should apply at.']);
        }

        if ($mode === 'on_pulse' && (isset($clean['apply_mode']) || array_key_exists('apply_url', $clean))) {
            $clean['apply_url'] = null;
        }

        unset($clean['publish']);

        return $clean;
    }
}

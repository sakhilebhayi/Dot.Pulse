<?php

namespace App\Livewire\Pulse\Need;

use App\Livewire\Pulse\Business\Support\HandlesServiceErrors;
use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\PulseNeed;
use App\Models\PulsePlace;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use App\Services\Pulse\Discovery\Taxonomy;
use App\Services\Pulse\Entities\NeedService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "Say what you want": a person's need. Create (route needs.create) and edit (needs.edit). A need goes live when it is
 * posted, after the content check, and ends on its own date. Property names are the service's own keys.
 */
class NeedForm extends Component
{
    use HandlesServiceErrors;

    public ?int $needId = null;

    public string $kind = 'need';

    public string $title = '';

    public string $details = '';

    /** @var list<string> */
    public array $tags = [];

    public string $tag_query = '';

    public string $budget_text = '';

    public string $needed_by = '';

    public string $reach = 'region';

    public ?int $place_id = null;

    public string $place_query = '';

    public string $visibility = 'open';

    public ?int $entity_id = null;

    public function mount(?PulseNeed $need = null): void
    {
        if ($need?->exists) {
            Gate::authorize('update', $need);

            $this->needId = $need->id;
            $this->kind = (string) $need->kind;
            $this->title = (string) $need->title;
            $this->details = (string) $need->details;
            $this->tags = array_values((array) $need->tags);
            $this->budget_text = (string) $need->budget_text;
            $this->needed_by = $need->needed_by ? $need->needed_by->format('Y-m-d') : '';
            $this->reach = in_array($need->reach, ['local', 'region', 'national', 'global', 'remote'], true) ? $need->reach : 'region';
            $this->place_id = $need->place_id;
            $this->place_query = $need->place ? app(PlaceRepository::class)->label($need->place) : '';
            $this->visibility = (string) $need->visibility;
            $this->entity_id = $need->entity_id;
        } else {
            $own = app(PlaceRepository::class)->forUser(auth()->user());

            if ($own) {
                $this->place_id = $own->id;
                $this->place_query = app(PlaceRepository::class)->label($own);
            }
        }
    }

    /** @return array<string, string> */
    public static function kinds(): array
    {
        return [
            'need' => 'Something to buy or get done',
            'supplier' => 'A supplier or a stockist',
            'collab' => 'A partner to build with',
            'investor' => 'An investor',
            'mentor' => 'A mentor',
            'intro' => 'An introduction',
        ];
    }

    #[Computed]
    public function atQuota(): bool
    {
        return $this->needId === null
            && PulseNeed::query()->where('user_id', auth()->id())->where('status', 'open')->count() >= (int) config('pulse.entities.max_open_needs', 10);
    }

    /** @return array<int, string> pages the person belongs to, to post a need on behalf of */
    #[Computed]
    public function pages(): array
    {
        return PulseEntity::query()->whereIn('id', PulseEntityMember::query()->where('user_id', auth()->id())->select('entity_id'))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return list<array{id: int, label: string}> */
    #[Computed]
    public function placeResults(): array
    {
        $q = trim($this->place_query);

        if ($this->place_id !== null || mb_strlen($q) < 2) {
            return [];
        }

        $places = app(PlaceRepository::class);

        return $places->search($q, 6)->map(fn (PulsePlace $p) => ['id' => $p->id, 'label' => $places->label($p)])->all();
    }

    /** @return list<array{slug: string, label: string, group: string}> */
    #[Computed]
    public function tagResults(): array
    {
        $q = trim($this->tag_query);

        if (mb_strlen($q) < 2) {
            return [];
        }

        return collect(app(Taxonomy::class)->suggest($q, 'any', 8))
            ->reject(fn ($h) => in_array($h['label'], $this->tags, true))
            ->values()->take(6)->all();
    }

    public function updatedPlaceQuery(): void
    {
        $this->place_id = null;
    }

    public function choosePlace(int $id): void
    {
        $place = app(PlaceRepository::class)->find($id);

        if ($place) {
            $this->place_id = $place->id;
            $this->place_query = app(PlaceRepository::class)->label($place);
        }
    }

    public function clearPlace(): void
    {
        $this->place_id = null;
        $this->place_query = '';
    }

    public function addTag(?string $label = null): void
    {
        $label = trim($label ?? $this->tag_query);
        $label = mb_substr($label, 0, 30);

        if ($label === '' || in_array($label, $this->tags, true)) {
            $this->tag_query = '';

            return;
        }

        if (count($this->tags) >= 10) {
            $this->addError('tags', 'Ten tags is the most. Remove one first.');

            return;
        }

        $this->resetErrorBag('tags');
        $this->tags[] = $label;
        $this->tag_query = '';
    }

    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);
        $this->tags = array_values($this->tags);
    }

    public function save(NeedService $needs): mixed
    {
        $this->resetErrorBag();
        $user = auth()->user();

        $data = [
            'title' => $this->title,
            'details' => $this->details ?: null,
            'kind' => $this->kind,
            'tags' => $this->tags,
            'budget_text' => $this->budget_text ?: null,
            'needed_by' => $this->needed_by ?: null,
            'place_id' => $this->place_id,
            'reach' => $this->reach,
            'visibility' => $this->visibility,
            'entity_id' => $this->entity_id ?: null,
        ];

        if (in_array($this->reach, ['local', 'region'], true) && $this->place_id === null) {
            $this->addError('place_query', 'Pick a place so Pulse knows what "near" means. Or choose "Anywhere".');

            return null;
        }

        try {
            if ($this->needId === null) {
                $need = $needs->create($user, $data);
            } else {
                $need = $needs->update($user, PulseNeed::findOrFail($this->needId), $data);
            }
        } catch (ValidationException $e) {
            $this->fail($e, ['content' => 'title', 'need' => 'title']);

            return null;
        } catch (AuthorizationException) {
            $this->addError('title', 'This is not your need to change.');

            return null;
        }

        if ($need->moderation === 'approved') {
            app(\App\Services\Pulse\Discovery\Matcher::class)->refreshAfter($user, 'need', $need->id);
        }

        session()->flash('bz_notice', match (true) {
            $need->moderation === 'approved' => $this->needId ? 'Saved.' : 'Posted. Pulse will show it to sellers and people it fits.',
            default => 'Saved. Waiting for a human: nobody else sees it until a person has looked. '.pulse_review_note(),
        });

        return $this->redirectRoute('needs.show', ['need' => $need->id]);
    }

    public function render(): View
    {
        return view('livewire.pulse.need.need-form', ['editing' => $this->needId !== null]);
    }
}

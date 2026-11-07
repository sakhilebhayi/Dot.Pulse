<?php

namespace App\Livewire\Pulse\Business;

use App\Livewire\Pulse\Business\Support\HandlesServiceErrors;
use App\Models\PulseEntity;
use App\Models\PulseOffer;
use App\Models\PulsePlace;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use App\Services\Pulse\Discovery\Taxonomy;
use App\Services\Pulse\Entities\MediaService;
use App\Services\Pulse\Entities\OfferService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The Offers tab: every offer of the page, and the editor to create or change one. Each button calls OfferService,
 * which re-checks the role and the moderation rules; a listing that needs a human says so, and why.
 */
class OfferManager extends Component
{
    use HandlesServiceErrors;
    use WithFileUploads;

    public int $entityId;

    /** list | edit */
    public string $mode = 'list';

    public ?int $offerId = null;

    public string $title = '';

    public string $summary = '';

    public string $description = '';

    public string $kind = 'service';

    public string $category = '';

    public string $tags_text = '';

    public string $price_mode = 'quote';

    public string $amount = '';

    public string $unit = '';

    public ?int $place_id = null;

    public string $place_query = '';

    public string $availability = 'available';

    public bool $accepts_quotes = true;

    public string $expires_at = '';

    /** @var list<int> */
    public array $media_ids = [];

    /** @var array<int, mixed> */
    public array $newImages = [];

    public ?string $notice = null;

    public function mount(PulseEntity $entity): void
    {
        Gate::authorize('manageListings', $entity);
        $this->entityId = $entity->id;
    }

    #[Computed]
    public function entity(): PulseEntity
    {
        return PulseEntity::query()->findOrFail($this->entityId);
    }

    /** @return Collection<int, PulseOffer> */
    #[Computed]
    public function offers()
    {
        return $this->entity->offers()->latest('id')->get();
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

    /** @return list<array{slug: string, label: string}> */
    #[Computed]
    public function categoryResults(): array
    {
        $q = trim($this->category);

        if (mb_strlen($q) < 2) {
            return [];
        }

        $hits = collect(app(Taxonomy::class)->suggest($q, 'topic', 5));

        return $hits->contains(fn ($h) => mb_strtolower($h['label']) === mb_strtolower($q)) ? [] : $hits->all();
    }

    public function newOffer(): void
    {
        $this->authorizeManage();
        $this->resetForm();
        $this->mode = 'edit';
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $o = $this->find($id);

        $this->resetForm();
        $this->offerId = $o->id;
        $this->title = (string) $o->title;
        $this->summary = (string) $o->summary;
        $this->description = (string) $o->description;
        $this->kind = (string) $o->kind;
        $this->category = (string) $o->category;
        $this->tags_text = implode(', ', (array) $o->tags);
        $this->price_mode = (string) $o->price_mode;
        $this->amount = $o->amount_cents !== null ? rtrim(rtrim(number_format($o->amount_cents / 100, 2, '.', ''), '0'), '.') : '';
        $this->unit = (string) $o->unit;
        $this->place_id = $o->place_id;
        $this->place_query = $o->place ? app(PlaceRepository::class)->label($o->place) : '';
        $this->availability = (string) $o->availability;
        $this->accepts_quotes = (bool) $o->accepts_quotes;
        $this->expires_at = $o->expires_at ? $o->expires_at->format('Y-m-d') : '';
        $this->media_ids = array_map('intval', (array) $o->media_ids);
        $this->mode = 'edit';
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->mode = 'list';
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

    public function chooseCategory(string $label): void
    {
        $this->category = mb_substr($label, 0, 80);
    }

    public function save(OfferService $offers, MediaService $media, bool $publish = false): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();
        $this->notice = null;

        $this->validate(['newImages.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp']], [
            'newImages.*.mimes' => 'Use JPEG, PNG or WebP images.',
            'newImages.*.max' => 'Images can be up to 5 MB.',
        ]);

        $user = auth()->user();
        $entity = $this->entity;
        $ids = $this->media_ids;

        try {
            foreach ($this->newImages as $file) {
                if (count($ids) >= (int) config('pulse.entities.max_offer_images', 8)) {
                    $this->addError('newImages', 'An offer can hold '.(int) config('pulse.entities.max_offer_images', 8).' pictures.');
                    break;
                }

                $ids[] = $media->store($user, $file, 'offer', $entity);
            }
        } catch (ValidationException $e) {
            $this->addError('newImages', $this->firstError($e));

            return;
        }

        $data = $this->payload($ids);

        try {
            if ($this->offerId === null) {
                $offer = $offers->create($user, $entity, $data);
                $this->offerId = $offer->id;
            } else {
                $offer = $offers->update($user, $this->find($this->offerId), $data);
            }

            $this->media_ids = $ids;
            $this->newImages = [];

            if ($publish) {
                $offer = $offers->publish($user, $offer);

                if ($offer->isLive()) {
                    app(\App\Services\Pulse\Discovery\Matcher::class)->refreshAfter($user, 'offer', $offer->id);
                }
            }
        } catch (ValidationException $e) {
            $this->fail($e, ['amount_cents' => 'amount', 'entity' => 'title', 'offer' => 'title', 'media_ids' => 'newImages']);

            return;
        }

        unset($this->offers, $this->entity);
        $this->notice = $this->saidAbout($offer, $publish);
        $this->resetForm();
        $this->mode = 'list';
    }

    public function savePublish(OfferService $offers, MediaService $media): void
    {
        $this->save($offers, $media, publish: true);
    }

    public function removeImage(int $mediaId, OfferService $offers, MediaService $media): void
    {
        $this->authorizeManage();

        if (! in_array($mediaId, $this->media_ids, true)) {
            return;
        }

        $this->media_ids = array_values(array_diff($this->media_ids, [$mediaId]));

        try {
            if ($this->offerId !== null) {
                $offers->update(auth()->user(), $this->find($this->offerId), ['media_ids' => $this->media_ids]);
            }

            $media->delete(auth()->user(), $mediaId);
        } catch (ValidationException $e) {
            $this->fail($e, ['media_ids' => 'newImages']);
        }
    }

    public function pause(int $id, OfferService $offers): void
    {
        $this->act(fn () => $offers->pause(auth()->user(), $this->find($id)), 'Paused. It is off the floor until you publish it again.');
    }

    public function publish(int $id, OfferService $offers): void
    {
        $this->act(function () use ($offers, $id) {
            $o = $offers->publish(auth()->user(), $this->find($id));

            return $this->saidAbout($o, true);
        });
    }

    public function archive(int $id, OfferService $offers): void
    {
        $this->act(fn () => $offers->archive(auth()->user(), $this->find($id)), 'Archived. You can publish it again later.');
    }

    public function delete(int $id, OfferService $offers): void
    {
        $this->act(fn () => $offers->delete(auth()->user(), $this->find($id)), 'Deleted.');
    }

    public function render(): View
    {
        return view('livewire.pulse.business.offer-manager');
    }

    // ---- internals ----------------------------------------------------------------------------------------

    private function authorizeManage(): void
    {
        Gate::authorize('manageListings', $this->entity);
    }

    private function find(int $id): PulseOffer
    {
        return $this->entity->offers()->with('place')->findOrFail($id);
    }

    /** Runs one button's action, refreshes the list and says the result. */
    private function act(callable $do, ?string $done = null): void
    {
        $this->authorizeManage();
        $this->notice = null;
        $this->resetErrorBag();

        try {
            $result = $do();
        } catch (ValidationException $e) {
            $this->notice = $this->firstError($e);
            unset($this->offers);

            return;
        }

        unset($this->offers);
        $this->notice = is_string($result) ? $result : $done;
    }

    /** @param list<int> $ids
     *  @return array<string, mixed> */
    private function payload(array $ids): array
    {
        $fixed = in_array($this->price_mode, ['fixed', 'from'], true);

        return [
            'title' => $this->title,
            'summary' => $this->summary ?: null,
            'description' => $this->description ?: null,
            'kind' => $this->kind,
            'category' => $this->category ?: null,
            'tags' => array_values(array_filter(array_map('trim', explode(',', $this->tags_text)))),
            'price_mode' => $this->price_mode,
            'amount_cents' => $fixed && $this->amount !== '' ? (int) round(((float) str_replace([' ', ','], ['', '.'], $this->amount)) * 100) : null,
            'unit' => $this->unit ?: null,
            'place_id' => $this->place_id,
            'availability' => $this->availability,
            'accepts_quotes' => $this->accepts_quotes,
            'expires_at' => $this->expires_at ?: null,
            'media_ids' => $ids,
        ];
    }

    private function saidAbout(PulseOffer $o, bool $published): string
    {
        if (! $published && $o->status !== 'live') {
            return 'Saved as a draft. Publish it when it is ready.';
        }

        return match ($o->moderation) {
            'approved' => 'Published. It is on the floor.',
            'removed' => 'A moderator removed this offer.',
            default => 'Saved. Waiting for a human: it is not on the floor until a person has looked at it. '.pulse_review_note(),
        };
    }

    private function resetForm(): void
    {
        $this->reset(['offerId', 'title', 'summary', 'description', 'kind', 'category', 'tags_text', 'price_mode', 'amount', 'unit', 'place_id', 'place_query', 'availability', 'accepts_quotes', 'expires_at', 'media_ids', 'newImages']);
        $this->resetErrorBag();
    }
}

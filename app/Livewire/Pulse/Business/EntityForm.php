<?php

namespace App\Livewire\Pulse\Business;

use App\Models\PulseEntity;
use App\Models\PulsePlace;
use App\Models\Team;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use App\Services\Pulse\Discovery\Taxonomy;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Entities\InfoDotLink;
use App\Services\Pulse\Entities\MediaService;
use App\Services\Pulse\Entities\VerificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The business page form: create (route entities.create) and edit (the Page tab of the manage screen).
 *
 * Property names are the service's own keys so that every validation message from EntityService lands on the right
 * field. Pictures upload through MediaService; on create they are stored after the page exists (an image belongs to a
 * page). Registration number, website and phone are claims and are labelled as claims.
 */
class EntityForm extends Component
{
    use WithFileUploads;

    public ?int $entityId = null;

    public string $name = '';

    public string $tagline = '';

    public string $about = '';

    public bool $is_seller = true;

    public bool $is_employer = false;

    public string $industry = '';

    public ?int $place_id = null;

    public string $place_query = '';

    public string $service_area_text = '';

    public string $contact_policy = 'anyone';

    public string $team_size = '';

    public string $founded_year = '';

    public string $website = '';

    public string $public_email = '';

    public string $public_phone = '';

    public string $registration_number = '';

    public string $culture_work_mode = '';

    public string $culture_values = '';

    public string $culture_perks = '';

    public ?string $infodot_team = null;

    public $logo = null;

    public $cover = null;

    public bool $remove_logo = false;

    public bool $remove_cover = false;

    public ?string $notice = null;

    public function mount(?PulseEntity $entity = null): void
    {
        if ($entity?->exists) {
            Gate::authorize('update', $entity);

            $this->entityId = $entity->id;
            $this->name = (string) $entity->name;
            $this->tagline = (string) $entity->tagline;
            $this->about = (string) $entity->about;
            $this->is_seller = (bool) $entity->is_seller;
            $this->is_employer = (bool) $entity->is_employer;
            $this->industry = (string) $entity->industry;
            $this->place_id = $entity->place_id;
            $this->place_query = $entity->place ? app(PlaceRepository::class)->label($entity->place) : '';
            $this->service_area_text = implode(', ', (array) $entity->service_area);
            $this->contact_policy = (string) $entity->contact_policy;
            $this->team_size = (string) $entity->team_size;
            $this->founded_year = (string) ($entity->founded_year ?? '');
            $this->website = (string) $entity->website;
            $this->public_email = (string) $entity->public_email;
            $this->public_phone = (string) $entity->public_phone;
            $this->registration_number = (string) $entity->registration_number;
            $culture = (array) $entity->culture;
            $this->culture_work_mode = (string) ($culture['work_mode'] ?? '');
            $this->culture_values = implode(', ', (array) ($culture['values'] ?? []));
            $this->culture_perks = implode(', ', (array) ($culture['perks'] ?? []));
        }
    }

    #[Computed]
    public function entity(): ?PulseEntity
    {
        return $this->entityId ? PulseEntity::query()->with(['place', 'logo', 'cover'])->find($this->entityId) : null;
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
    public function industryResults(): array
    {
        $q = trim($this->industry);

        if (mb_strlen($q) < 2) {
            return [];
        }

        $hits = collect(app(Taxonomy::class)->suggest($q, 'topic', 6));

        // An exact match is already chosen: say nothing more.
        return $hits->contains(fn ($h) => mb_strtolower($h['label']) === mb_strtolower($q)) ? [] : $hits->all();
    }

    /** @return list<array{id: string, name: string}> InfoDot companies this person owns or administers and no page uses yet */
    #[Computed]
    public function companies(): array
    {
        $user = auth()->user();

        return InfoDotLink::teams($user)
            ->filter(fn (Team $t) => InfoDotLink::manages($user, $t)
                && ! PulseEntity::withTrashed()->where('infodot_business_ref', $t->infodot_team_uuid)->where('id', '!=', (int) $this->entityId)->exists())
            ->map(fn (Team $t) => ['id' => $t->infodot_team_uuid, 'name' => $t->name])
            ->values()
            ->all();
    }

    /** @return list<PulseEntity> the pages this person already runs, for the quota message */
    #[Computed]
    public function ownPages(): array
    {
        return PulseEntity::query()->where('created_by', auth()->id())->orderBy('name')->get()->all();
    }

    #[Computed]
    public function atQuota(): bool
    {
        return $this->entityId === null && count($this->ownPages) >= (int) config('pulse.entities.max_per_user', 5);
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

    public function chooseIndustry(string $label): void
    {
        $this->industry = mb_substr($label, 0, 80);
    }

    /** Create, then go live (publish) or stay off the floor (draft). */
    public function save(EntityService $entities, MediaService $media, VerificationService $verification, bool $draft = false): mixed
    {
        $this->resetErrorBag();
        $this->notice = null;

        $this->validate([
            'logo' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'cover' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ], [
            'logo.mimes' => 'Use a JPEG, PNG or WebP image.',
            'cover.mimes' => 'Use a JPEG, PNG or WebP image.',
            'logo.max' => 'Images can be up to 5 MB.',
            'cover.max' => 'Images can be up to 5 MB.',
        ]);

        $user = auth()->user();
        $data = $this->payload();

        try {
            if ($this->entityId === null) {
                $entity = $entities->create($user, $data + ['draft' => $draft]);
                $this->entityId = $entity->id;
            } else {
                $entity = $entities->update($user, PulseEntity::findOrFail($this->entityId), $data);
            }
        } catch (AuthorizationException) {
            $this->addError('name', 'You do not have the role on this page to change its details.');

            return null;
        }

        $problems = $this->applyPictures($entities, $media, $entity);

        if (filled($this->registration_number) && $this->registration_number !== (string) $entity->registration_number) {
            try {
                $verification->claimRegistration($user, $entity, $this->registration_number);
            } catch (ValidationException $e) {
                $this->addError('registration_number', $e->errors()['number'][0] ?? 'Check the registration number.');
                $problems[] = 'registration_number';
            }
        }

        $linked = null;

        if ($this->infodot_team && $entity->infodot_business_ref !== $this->infodot_team) {
            $team = Team::query()->where('infodot_team_uuid', $this->infodot_team)->first();

            try {
                if ($team) {
                    $verification->linkInfoDotCompany($user, $entity->refresh(), $team);
                    $linked = $team->name;
                }
            } catch (ValidationException $e) {
                $this->addError('infodot_team', $e->errors()['team'][0] ?? 'That company could not be linked.');
                $problems[] = 'infodot_team';
            }
        }

        unset($this->entity);
        $this->logo = $this->cover = null;
        $this->remove_logo = $this->remove_cover = false;

        if ($problems !== []) {
            $this->notice = 'The page is saved. Fix what is marked below and save again.';

            return null;
        }

        $fresh = $entity->refresh();
        session()->flash('bz_notice', $this->summary($fresh, $draft, $linked));

        return $this->redirectRoute('entities.manage', ['entity' => $fresh->slug], navigate: false);
    }

    public function saveDraft(EntityService $entities, MediaService $media, VerificationService $verification): mixed
    {
        return $this->save($entities, $media, $verification, draft: true);
    }

    public function putOnFloor(EntityService $entities): void
    {
        $entity = PulseEntity::findOrFail($this->entityId);

        try {
            $wasDraft = $entity->status === 'draft';
            $entities->restore(auth()->user(), $entity);
            $this->notice = $wasDraft ? 'Your page is on the floor.' : 'Your page is back on the floor.';
        } catch (AuthorizationException) {
            $this->notice = 'Only an owner can put the page back on the floor.';
        } catch (ValidationException $e) {
            $this->notice = collect($e->errors())->flatten()->first();
        }

        unset($this->entity);
    }

    public function takeOff(EntityService $entities): void
    {
        $entity = PulseEntity::findOrFail($this->entityId);

        try {
            $entities->archive(auth()->user(), $entity);
            $this->notice = 'Your page is off the floor. Only your team can see it.';
        } catch (AuthorizationException) {
            $this->notice = 'Only an owner can take the page off the floor.';
        }

        unset($this->entity);
    }

    public function render(): View
    {
        return view('livewire.pulse.business.entity-form', [
            'editing' => $this->entityId !== null,
            'logoPreview' => $this->previewUrl($this->logo),
            'coverPreview' => $this->previewUrl($this->cover),
        ]);
    }

    // ---- internals ----------------------------------------------------------------------------------------

    private function previewUrl(mixed $file): ?string
    {
        try {
            return $file && method_exists($file, 'isPreviewable') && $file->isPreviewable() ? $file->temporaryUrl() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $culture = array_filter([
            'work_mode' => $this->culture_work_mode ?: null,
            'values' => $this->list($this->culture_values),
            'perks' => $this->list($this->culture_perks),
        ]);

        $data = [
            'name' => $this->name,
            'tagline' => $this->tagline ?: null,
            'about' => $this->about ?: null,
            'is_seller' => $this->is_seller,
            'is_employer' => $this->is_employer,
            'industry' => $this->industry ?: null,
            'place_id' => $this->place_id,
            'service_area' => $this->list($this->service_area_text),
            'website' => $this->website ?: null,
            'public_email' => $this->public_email ?: null,
            'public_phone' => $this->public_phone ?: null,
            'team_size' => $this->team_size ?: null,
            'founded_year' => $this->founded_year !== '' ? (int) $this->founded_year : null,
            'contact_policy' => $this->contact_policy,
            'culture' => $this->is_employer ? $culture : [],
        ];

        if ($this->entityId === null) {
            // create() takes the same keys; a blank optional field is simply absent
            $data = array_filter($data, fn ($v) => $v !== null && $v !== [] && $v !== '');
            $data['is_seller'] = $this->is_seller;
            $data['is_employer'] = $this->is_employer;
        }

        return $data;
    }

    /** @return list<string> */
    private function list(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $text) ?: [])));
    }

    /** @return list<string> the fields that could not be saved */
    private function applyPictures(EntityService $entities, MediaService $media, PulseEntity $entity): array
    {
        $user = auth()->user();
        $problems = [];
        $set = [];
        $drop = [];

        foreach (['logo' => 'logo_media_id', 'cover' => 'cover_media_id'] as $purpose => $column) {
            $file = $this->{$purpose};
            $removeFlag = 'remove_'.$purpose;

            if ($file) {
                try {
                    $set[$column] = $media->store($user, $file, $purpose, $entity);

                    if ($entity->{$column}) {
                        $drop[] = (int) $entity->{$column};
                    }
                } catch (ValidationException $e) {
                    $this->addError($purpose, $e->errors()['file'][0] ?? 'That image could not be used.');
                    $problems[] = $purpose;
                } catch (AuthorizationException) {
                    $this->addError($purpose, 'You cannot change pictures on this page.');
                    $problems[] = $purpose;
                }
            } elseif ($this->{$removeFlag} && $entity->{$column}) {
                $set[$column] = null;
                $drop[] = (int) $entity->{$column};
            }
        }

        if ($set !== []) {
            $entities->update($user, $entity, $set);
        }

        foreach ($drop as $id) {
            try {
                $media->delete($user, $id);
            } catch (\Throwable) {
                // the old file stays; the page already points at the new one
            }
        }

        return $problems;
    }

    private function summary(PulseEntity $e, bool $draft, ?string $linked): string
    {
        $text = match (true) {
            $e->status === 'draft' => 'Saved as a draft. Only your team can see it. Put it on the floor from here when it is ready.',
            $e->status === 'archived' => 'Saved. The page is off the floor. Only your team can see it.',
            $e->moderation !== 'approved' => 'Saved. A person has to look at the page before the public sees it.',
            default => 'Your page is live.',
        };

        return $linked ? $text.' Linked to the InfoDot company '.$linked.'.' : $text;
    }
}

<?php

namespace App\Livewire\Pulse\Me\Concerns;

use App\Models\PulsePlace;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use Illuminate\Support\Collection;

/** A typed-ahead place picker over the gazetteer. A place is declared and coarse; no coordinate of a person is stored. */
trait PicksPlace
{
    public string $placeQuery = '';

    public ?int $placeId = null;

    /** @return Collection<int, PulsePlace> */
    public function placeResults(): Collection
    {
        return mb_strlen(trim($this->placeQuery)) >= 2 ? app(PlaceRepository::class)->search($this->placeQuery, 8) : collect();
    }

    public function pickPlace(int $id): void
    {
        if (app(PlaceRepository::class)->find($id) === null) {
            return;
        }

        $this->placeId = $id;
        $this->placeQuery = '';
        $this->placeChanged();
    }

    public function clearPlace(): void
    {
        $this->placeId = null;
        $this->placeQuery = '';
        $this->placeChanged();
    }

    /** Hook: a component that saves on every change overrides this. */
    protected function placeChanged(): void {}

    public function chosenPlaceName(): ?string
    {
        $place = $this->placeId ? app(PlaceRepository::class)->find($this->placeId) : null;

        return $place ? $this->placeLabel($place) : null;
    }

    public function placeLabel(PulsePlace $p): string
    {
        return $p->level === 'city' && $p->region ? "{$p->name}, {$p->region}" : $p->name;
    }
}

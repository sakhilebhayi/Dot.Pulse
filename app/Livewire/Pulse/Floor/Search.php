<?php

namespace App\Livewire\Pulse\Floor;

use App\Livewire\Pulse\Floor\Concerns\ActsOnBeats;
use App\Livewire\Pulse\Floor\Support\BeatPresenter;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use App\Services\Pulse\Discovery\SearchService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Search: signals and business pages, filtered, each result with the reason it matched. The whole state lives in the
 * URL (q, kind, mode, place, remote, min, max, type) so a search can be shared and the back button works.
 */
class Search extends Component
{
    use ActsOnBeats;

    public const KINDS = [
        'offer' => 'Offers', 'need' => 'Needs', 'role' => 'Roles', 'talent' => 'People looking for work',
        'ask' => 'Asks', 'event' => 'Events', 'news' => 'News', 'business' => 'Businesses',
    ];

    public const MODES = ['trade' => 'Trade', 'work' => 'Work', 'local' => 'Local', 'learn' => 'Learn'];

    public const TYPES = ['full_time' => 'Full time', 'part_time' => 'Part time', 'contract' => 'Contract', 'internship' => 'Internship', 'freelance' => 'Freelance'];

    #[Url(as: 'q', history: true, except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $mode = '';

    /** A place slug. */
    #[Url(except: '')]
    public string $place = '';

    #[Url(except: false)]
    public bool $remote = false;

    #[Url(as: 'min', except: '')]
    public string $priceMin = '';

    #[Url(as: 'max', except: '')]
    public string $priceMax = '';

    #[Url(as: 'type', except: '')]
    public string $employment = '';

    public string $placeText = '';

    public bool $suggestOpen = false;

    #[Locked]
    public ?string $cursor = null;

    #[Locked]
    public int $total = 0;

    #[Locked]
    public string $ran = '';

    public function mount(): void
    {
        $this->clean();
        $this->run();
    }

    public function updated(string $name): void
    {
        // Only typing opens the suggestions; a submit, a pick or any filter closes them.
        $this->suggestOpen = $name === 'q' && mb_strlen(trim($this->q)) >= 2;

        if (in_array($name, ['q', 'kind', 'mode', 'place', 'remote', 'priceMin', 'priceMax', 'employment'], true)) {
            $this->clean();
            $this->run();
        }
    }

    public function submit(): void
    {
        $this->suggestOpen = false;
        $this->clean();
        $this->run();
    }

    public function pick(string $value): void
    {
        $this->q = mb_substr($value, 0, 120);
        $this->suggestOpen = false;
        $this->run();
    }

    public function pickPlace(string $slug, PlaceRepository $places): void
    {
        if ($places->findBySlug($slug) !== null) {
            $this->place = $slug;
            $this->placeText = '';
            $this->run();
        }
    }

    public function clearPlace(): void
    {
        $this->place = '';
        $this->run();
    }

    public function clearFilters(): void
    {
        $this->reset(['kind', 'mode', 'place', 'remote', 'priceMin', 'priceMax', 'employment', 'placeText']);
        $this->run();
    }

    public function more(SearchService $search): void
    {
        if ($this->cursor === null) {
            return;
        }

        $out = $search->search(auth()->user(), $this->q, $this->filters(), $this->cursor);
        $this->beats = array_merge($this->beats, $this->rows($out['results']));
        $this->cursor = $out['next'];
    }

    private function run(): void
    {
        $out = app(SearchService::class)->search(auth()->user(), $this->q, $this->filters());
        $this->beats = $this->rows($out['results']);
        $this->cursor = $out['next'];
        $this->total = $out['total'];
        $this->ran = trim($this->q);
    }

    /** Keep only values the service understands; the URL is user input. */
    private function clean(): void
    {
        $this->q = mb_substr(trim($this->q), 0, 120);
        $this->kind = isset(self::KINDS[$this->kind]) ? $this->kind : '';
        $this->mode = isset(self::MODES[$this->mode]) ? $this->mode : '';
        $this->employment = isset(self::TYPES[$this->employment]) ? $this->employment : '';
        $this->priceMin = is_numeric($this->priceMin) && (float) $this->priceMin >= 0 ? (string) min((float) $this->priceMin, 100000000) : '';
        $this->priceMax = is_numeric($this->priceMax) && (float) $this->priceMax >= 0 ? (string) min((float) $this->priceMax, 100000000) : '';
        $this->place = preg_match('/^[a-z0-9-]{1,120}$/', $this->place) ? $this->place : '';
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'kind' => $this->kind ?: null,
            'mode' => $this->mode ?: null,
            'place' => $this->place ?: null,
            'remote' => $this->remote ?: null,
            'price_min' => $this->priceMin !== '' ? $this->priceMin : null,
            'price_max' => $this->priceMax !== '' ? $this->priceMax : null,
            'employment_type' => $this->employment ?: null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function rows(array $results): array
    {
        $presenter = app(BeatPresenter::class);
        $rows = [];

        foreach ($results as $r) {
            if ($r['type'] === 'signal' && $r['beat'] !== null) {
                $rows[] = $presenter->present($r['beat'], auth()->user()) + ['result' => 'signal', 'why' => $r['why']];
            } elseif ($r['type'] === 'entity') {
                $rows[] = ['result' => 'entity', 'entity' => $r['entity'], 'why' => $r['why']];
            }
        }

        return $rows;
    }

    public function render(PlaceRepository $places, SearchService $search): View
    {
        $chosen = $this->place !== '' ? $places->findBySlug($this->place) : null;
        $typing = trim($this->placeText);

        return view('livewire.pulse.floor.search', [
            'suggestions' => $this->suggestOpen && mb_strlen(trim($this->q)) >= 2 ? $search->suggest(auth()->user(), $this->q, 6) : [],
            'placeLabel' => $chosen ? $places->label($chosen) : null,
            'placeMatches' => $chosen === null && mb_strlen($typing) >= 2 ? $places->search($typing, 6) : collect(),
            'places' => $places,
            'hasFilters' => $this->kind !== '' || $this->mode !== '' || $this->place !== '' || $this->remote || $this->priceMin !== '' || $this->priceMax !== '' || $this->employment !== '',
            'searched' => $this->ran !== '' || $this->kind !== '' || $this->mode !== '' || $this->place !== '' || $this->remote || $this->priceMin !== '' || $this->priceMax !== '' || $this->employment !== '',
        ]);
    }
}

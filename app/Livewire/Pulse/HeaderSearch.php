<?php

namespace App\Livewire\Pulse;

use App\Models\User;
use App\Services\Pulse\Discovery\SearchService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The search in the top bar (the shared dot-ui pill) on every page of the signed-in app.
 *
 * It does not search by itself: the typed-ahead rows come from SearchService::suggest() (curated topics and skills, business names,
 * live listing titles the person is allowed to see, blocked and muted people and pages left out, throttled per person), and every
 * row and the Enter key lead to the existing /search page with the same `q` parameter. Nothing is queried until the box has been
 * focused, so a page that never uses the search carries no extra queries.
 */
class HeaderSearch extends Component
{
    public string $query = '';

    /** Set when the box is first focused. */
    public bool $touched = false;

    public function mount(string $query = ''): void
    {
        $this->query = $query;
    }

    public function touch(): void
    {
        $this->touched = true;
    }

    public function render(SearchService $search): View
    {
        /** @var User|null $me */
        $me = auth()->user();
        $q = trim($this->query);
        $rows = [];
        $limited = false;

        if ($this->touched && $me && mb_strlen($q) >= 2) {
            try {
                $rows = $search->suggest($me, $q, 8);
            } catch (HttpExceptionInterface) {
                // The per-person throttle answered 429: say so instead of an empty list that looks like "no match".
                $limited = true;
            }
        }

        return view('livewire.pulse.header-search', [
            'rows' => $rows,
            'typed' => mb_strlen($q) >= 2,
            'limited' => $limited,
            'safe' => Str::limit($q, 60),
        ]);
    }
}

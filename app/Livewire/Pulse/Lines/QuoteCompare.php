<?php

namespace App\Livewire\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseQuote;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\QuoteService;
use App\Services\Pulse\Lines\ResponseStats;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Up to three quote slips side by side, for the person who asked: price, earliest date, what is included and what
 * is not, and how reliably each business answers (shown only from real answered and received counts).
 *
 *   <livewire:pulse.lines.quote-compare :group="$quoteGroup" />
 */
class QuoteCompare extends Component
{
    #[Locked]
    public string $group = '';

    public ?string $failure = null;

    public function mount(string $group): void
    {
        $this->group = $group;
        abort_if($this->conversations()->isEmpty(), 404);
    }

    public function acceptQuote(int $quoteId, QuoteService $quotes): void
    {
        $this->attempt(fn ($user) => $quotes->accept($user, $this->quote($quoteId)), 'Quote accepted. The other businesses are told they were not chosen.');
    }

    public function declineQuote(int $quoteId, QuoteService $quotes): void
    {
        $this->attempt(fn ($user) => $quotes->decline($user, $this->quote($quoteId)), 'Quote declined.');
    }

    /** The 30 second refresh while the page is in view: the render does the work. */
    public function refresh(): void {}

    private function attempt(callable $action, string $toast): void
    {
        $this->failure = null;

        try {
            $action(auth()->user());
            $this->dispatch('toast', message: $toast, kind: 'good');
        } catch (LineException|ThrottleRequestsException $e) {
            $this->failure = $e->getMessage();
        }
    }

    /** A slip that belongs to this request, or a 404. */
    private function quote(int $id): PulseQuote
    {
        $quote = PulseQuote::query()->whereIn('pulse_conversation_id', $this->conversations()->pluck('id'))->find($id);
        abort_if($quote === null, 404);

        return $quote;
    }

    /**
     * The lines of this request that the viewer sent. Anyone else gets nothing, so a 404.
     *
     * @return Collection<int, PulseConversation>
     */
    private function conversations(): Collection
    {
        $user = auth()->user();

        return PulseConversation::query()->where('quote_group', $this->group)->visibleTo($user)
            ->with('entity:id,name,slug,logo_media_id')->orderBy('id')->get()
            ->filter(fn (PulseConversation $c) => Access::side($user, $c) === 'initiator')->values();
    }

    public function render(LineService $lines): View
    {
        $user = auth()->user();
        $conversations = $this->conversations();

        $quotes = PulseQuote::query()->whereIn('pulse_conversation_id', $conversations->pluck('id'))
            ->where('status', '!=', PulseQuote::SUPERSEDED)->orderBy('id')->get()->groupBy('pulse_conversation_id')
            ->map(fn ($rows) => $rows->last());

        $columns = $conversations->map(fn (PulseConversation $c) => [
            'conversation' => $c,
            'line' => $lines->present($user, $c),
            'entity' => $c->entity,
            'quote' => $quotes->get($c->id),
            'stats' => $c->entity ? ResponseStats::for($c->entity) : null,
        ]);

        $first = $conversations->first();

        return view('livewire.pulse.lines.quote-compare', [
            'columns' => $columns,
            'chosen' => $columns->first(fn ($col) => $col['quote']?->status === PulseQuote::ACCEPTED),
            'title' => $first->context_title,
            'card' => $first->card ?? [],
        ]);
    }
}

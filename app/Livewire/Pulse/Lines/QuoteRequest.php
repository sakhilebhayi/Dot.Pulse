<?php

namespace App\Livewire\Pulse\Lines;

use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\QuoteService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "Ask for quotes": pick up to three businesses and say what you need once. Each answers on the same Quote Slip,
 * and you compare them side by side. Three is the cap on purpose; more turns a request into a race.
 *
 *   <livewire:pulse.lines.quote-request :need-id="$need->id" />            from your own live need
 *   <livewire:pulse.lines.quote-request :offer-id="$offer->id" />          from an offer that takes quote requests
 *   optional :provider-ids="[3, 8]" preselects businesses (for example from a search result).
 */
class QuoteRequest extends Component
{
    public ?int $needId = null;

    public ?int $offerId = null;

    /** @var list<int> chosen businesses, at most three */
    public array $selected = [];

    /** @var array{what: string, when_or_qty: string, where: string, budget_band: string} */
    public array $card = ['what' => '', 'when_or_qty' => '', 'where' => '', 'budget_band' => ''];

    public string $search = '';

    public bool $open = false;

    public bool $sent = false;

    /** @var list<int> */
    public array $lineIds = [];

    public ?string $group = null;

    public ?string $failure = null;

    /** @param  list<int>  $providerIds */
    public function mount(?int $needId = null, ?int $offerId = null, array $providerIds = []): void
    {
        abort_if($needId === null && $offerId === null, 404);

        $this->needId = $needId;
        $this->offerId = $offerId;

        if ($needId !== null) {
            $need = PulseNeed::query()->where('user_id', auth()->id())->find($needId);
            abort_if($need === null, 404);
            $this->card['what'] = (string) $need->title;
            $this->card['when_or_qty'] = $need->needed_by ? 'By '.$need->needed_by->format('j M Y') : '';
            $this->card['budget_band'] = (string) ($need->budget_text ?? '');
        } else {
            $offer = PulseOffer::query()->find($offerId);
            abort_if($offer === null, 404);
            $this->card['what'] = (string) $offer->title;
        }

        $fixed = $this->fixedEntityId();
        $this->selected = collect($providerIds)->map(fn ($id) => (int) $id)->when($fixed, fn ($c) => $c->prepend($fixed))
            ->unique()->take(QuoteService::MAX_PROVIDERS)->values()->all();
    }

    public function openSheet(): void
    {
        $this->open = true;
        $this->failure = null;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /** Hold the cap on the server: a fourth is dropped and the person is told why. */
    public function updatedSelected(): void
    {
        $ids = collect($this->selected)->map(fn ($id) => (int) $id)->unique();
        $fixed = $this->fixedEntityId();

        if ($fixed !== null && ! $ids->contains($fixed)) {
            $ids->prepend($fixed);
        }

        if ($ids->count() > QuoteService::MAX_PROVIDERS) {
            $this->failure = 'Three is the most. More than three turns a request into a race, and nobody wins a race.';
        } else {
            $this->failure = null;
        }

        $this->selected = $ids->take(QuoteService::MAX_PROVIDERS)->values()->all();
    }

    public function send(QuoteService $quotes): void
    {
        $this->failure = null;

        $this->validate([
            'card.what' => ['required', 'string', 'min:3', 'max:200'],
            'card.when_or_qty' => ['nullable', 'string', 'max:80'],
            'card.where' => ['nullable', 'string', 'max:80'],
            'card.budget_band' => ['nullable', 'string', 'max:40'],
        ], [
            'card.what.required' => 'Say in a few words what you need.',
            'card.what.min' => 'Say in a few words what you need.',
        ]);

        if ($this->selected === []) {
            $this->failure = 'Pick at least one business to ask.';

            return;
        }

        try {
            $lines = $quotes->request(
                auth()->user(),
                $this->needId !== null ? ['need' => $this->needId] : ['offer' => $this->offerId],
                $this->selected,
                $this->card,
            );
        } catch (LineException $e) {
            $this->failure = $e->getMessage();

            return;
        } catch (ThrottleRequestsException $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->sent = true;
        $this->lineIds = collect($lines)->pluck('id')->all();
        $this->group = $lines[0]->quote_group ?? null;
        $this->dispatch('pulse-quote-requested', group: $this->group, lines: $this->lineIds);
    }

    /** For a request that starts from an offer, that offer's own business is always one of the three. */
    private function fixedEntityId(): ?int
    {
        return $this->offerId !== null ? PulseOffer::query()->whereKey($this->offerId)->value('entity_id') : null;
    }

    /**
     * Businesses that sell and take quote requests, best fit first. "Fits" is the real title of one of their quoting offers.
     *
     * @return Collection<int, array{entity: PulseEntity, fit: ?string}>
     */
    private function candidates(): Collection
    {
        $user = auth()->user();
        $mine = PulseEntityMember::query()->where('user_id', $user->id)->pluck('entity_id')->all();
        $hidden = BlockService::hiddenEntityIds($user);
        $fixed = $this->fixedEntityId();

        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($this->card['what'].' '.$this->search), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($w) => mb_strlen($w) >= 4)->unique()->values();

        $entities = PulseEntity::query()->live()->where('is_seller', true)
            ->whereNotIn('id', array_merge($mine, $hidden, $fixed ? [$fixed] : []))
            ->whereHas('offers', fn ($q) => $q->live()->where('accepts_quotes', true))
            ->with(['offers' => fn ($q) => $q->live()->where('accepts_quotes', true)->select('id', 'entity_id', 'title', 'category')])
            ->when(trim($this->search) !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($this->search)).'%']))
            ->orderBy('name')->limit(30)->get();

        return $entities->map(function (PulseEntity $e) use ($words) {
            $best = $e->offers->map(function ($o) use ($words) {
                $hay = mb_strtolower($o->title.' '.$o->category);

                return ['title' => $o->title, 'score' => $words->filter(fn ($w) => str_contains($hay, $w))->count()];
            })->sortByDesc('score')->first();

            return ['entity' => $e, 'fit' => ($best['score'] ?? 0) > 0 ? $best['title'] : null, 'score' => $best['score'] ?? 0];
        })->sortByDesc('score')->take(12)->values();
    }

    public function render(KnockBudget $budget): View
    {
        $fixed = $this->fixedEntityId();

        return view('livewire.pulse.lines.quote-request', [
            'candidates' => $this->open && ! $this->sent ? $this->candidates() : collect(),
            'fixed' => $fixed ? PulseEntity::query()->find($fixed) : null,
            'chosen' => PulseEntity::query()->whereIn('id', $this->selected)->get(['id', 'name'])->keyBy('id'),
            'cap' => QuoteService::MAX_PROVIDERS,
            'left' => $budget->remaining(auth()->user()),
        ]);
    }
}

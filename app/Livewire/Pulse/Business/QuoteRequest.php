<?php

namespace App\Livewire\Pulse\Business;

use App\Models\PulseOffer;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\QuoteService;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Ask for a quote" on an offer that accepts quotes. One request goes to this business (QuoteService allows at most
 * three businesses per request; this form asks one, and says so).
 */
class QuoteRequest extends Component
{
    #[Locked]
    public int $offerId;

    public bool $open = false;

    public bool $sent = false;

    #[Locked]
    public ?int $lineId = null;

    public string $what = '';

    public string $whenOrQty = '';

    public string $where = '';

    public string $budgetBand = '';

    public ?string $failure = null;

    public function mount(int $offerId): void
    {
        abort_unless(auth()->check(), 403);
        $this->offerId = $offerId;
        abort_if($this->offer() === null, 404);
    }

    public function offer(): ?PulseOffer
    {
        return PulseOffer::query()->with('entity')->find($this->offerId);
    }

    public function openForm(): void
    {
        $this->open = true;
        $this->failure = null;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function send(QuoteService $quotes): void
    {
        $this->failure = null;
        $offer = $this->offer();

        $this->validate([
            'what' => ['required', 'string', 'max:200'],
            'whenOrQty' => ['nullable', 'string', 'max:80'],
            'where' => ['nullable', 'string', 'max:80'],
            'budgetBand' => ['nullable', 'string', 'max:40'],
        ], [], ['what' => 'what you need quoted', 'whenOrQty' => 'when or how many', 'where' => 'where', 'budgetBand' => 'budget']);

        try {
            $lines = $quotes->request(auth()->user(), ['offer' => $offer->id], [$offer->entity_id], [
                'what' => $this->what,
                'when_or_qty' => $this->whenOrQty,
                'where' => $this->where,
                'budget_band' => $this->budgetBand,
            ]);
        } catch (LineException $e) {
            $this->failure = $e->getMessage();

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $field = match ($key) {
                    'card.what' => 'what', 'card.when_or_qty' => 'whenOrQty', 'card.where' => 'where', 'card.budget_band' => 'budgetBand', default => null,
                };
                $field ? $this->addError($field, $messages[0]) : ($this->failure = $messages[0]);
            }

            return;
        }

        $this->sent = true;
        $this->lineId = $lines[0]->id ?? null;
        $this->dispatch('pulse-quote-requested', line: $this->lineId);
    }

    public function render(): View
    {
        $offer = $this->offer();

        return view('livewire.pulse.business.quote-request', [
            'offer' => $offer,
            'own' => $offer && Access::isMemberOf(auth()->user(), $offer->entity_id),
            'max' => QuoteService::MAX_PROVIDERS,
        ]);
    }
}

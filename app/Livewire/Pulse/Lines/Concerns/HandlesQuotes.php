<?php

namespace App\Livewire\Pulse\Lines\Concerns;

use App\Models\PulseConversation;
use App\Models\PulseQuote;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\QuoteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Quote slips on a quote line. The business answers on a slip (price or range, earliest date, included, excluded,
 * how long it holds); the person who asked accepts or declines it.
 */
trait HandlesQuotes
{
    /** @var array<string, string> */
    public array $slip = [];

    protected function resetSlip(): void
    {
        $this->slip = [
            'amount' => '',
            'amount_max' => '',
            'unit' => '',
            'earliest_date' => '',
            'included' => '',
            'excluded' => '',
            'valid_until' => now()->addDays(7)->toDateString(),
            'notes' => '',
        ];
    }

    public function sendSlip(QuoteService $quotes): void
    {
        $this->failure = null;
        $c = $this->line();
        abort_unless(Gate::forUser(auth()->user())->allows('respond', $c), 403);

        $max = (int) config('pulse.lines.quote_max_validity_days', 90);

        $this->validate([
            'slip.amount' => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'slip.amount_max' => ['nullable', 'numeric', 'gt:slip.amount', 'max:1000000000'],
            'slip.unit' => ['nullable', 'string', 'max:24'],
            'slip.earliest_date' => ['required', 'date', 'after_or_equal:today'],
            'slip.included' => ['required', 'string', 'max:600'],
            'slip.excluded' => ['nullable', 'string', 'max:600'],
            'slip.valid_until' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addDays($max)->toDateString()],
            'slip.notes' => ['nullable', 'string', 'max:600'],
        ], [
            'slip.amount.required' => 'Put a price in rand. A range is fine: add the top of it below.',
            'slip.amount.numeric' => 'Use numbers only, like 1200 or 1200.50.',
            'slip.amount.min' => 'Put a price in rand.',
            'slip.amount_max.gt' => 'The top of the range must be more than the low end.',
            'slip.earliest_date.required' => 'Say the earliest date you can start.',
            'slip.earliest_date.after_or_equal' => 'The earliest date cannot be in the past.',
            'slip.included.required' => 'Say what the price covers.',
            'slip.valid_until.after_or_equal' => 'A slip must hold until today or later.',
            'slip.valid_until.before_or_equal' => 'A slip can hold for at most '.$max.' days.',
        ]);

        try {
            $quotes->respond(auth()->user(), $c, [
                'amount_cents' => $this->cents($this->slip['amount']),
                'amount_max_cents' => $this->slip['amount_max'] !== '' ? $this->cents($this->slip['amount_max']) : null,
                'unit' => $this->slip['unit'] !== '' ? $this->slip['unit'] : null,
                'earliest_date' => $this->slip['earliest_date'],
                'included' => $this->slip['included'],
                'excluded' => $this->slip['excluded'] !== '' ? $this->slip['excluded'] : null,
                'valid_until' => $this->slip['valid_until'],
                'notes' => $this->slip['notes'] !== '' ? $this->slip['notes'] : null,
            ]);
        } catch (ValidationException $e) {
            $map = ['amount_cents' => 'amount', 'amount_max_cents' => 'amount_max'];
            foreach ($e->errors() as $field => $messages) {
                $this->addError('slip.'.($map[$field] ?? $field), $messages[0]);
            }

            return;
        } catch (LineException $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->resetSlip();
        $this->dispatch('toast', message: 'Quote sent. It holds until the date you set.', kind: 'good');
    }

    public function acceptQuote(int $quoteId, QuoteService $quotes): void
    {
        $this->attempt(function ($user) use ($quoteId, $quotes) {
            $quotes->accept($user, $this->quoteOnThisLine($quoteId));
            $this->dispatch('toast', message: 'Quote accepted. The other businesses are told they were not chosen.', kind: 'good');
        });
    }

    public function declineQuote(int $quoteId, QuoteService $quotes): void
    {
        $this->attempt(function ($user) use ($quoteId, $quotes) {
            $quotes->decline($user, $this->quoteOnThisLine($quoteId));
            $this->dispatch('toast', message: 'Quote declined.', kind: 'good');
        });
    }

    /** A slip on this line only: an id from another line is a 404. */
    protected function quoteOnThisLine(int $quoteId): PulseQuote
    {
        $quote = PulseQuote::query()->where('pulse_conversation_id', $this->conversationId)->find($quoteId);
        abort_if($quote === null, 404);

        return $quote;
    }

    /** @return Collection<int, PulseQuote> slips on the line, keyed by id */
    protected function quotesFor(PulseConversation $c)
    {
        if ($c->kind !== 'quote') {
            return collect();
        }

        return PulseQuote::query()->where('pulse_conversation_id', $c->id)->get()->keyBy('id');
    }

    protected function canRespond(PulseConversation $c): bool
    {
        return Gate::forUser(auth()->user())->allows('respond', $c);
    }

    private function cents(string $rand): int
    {
        return (int) round(((float) $rand) * 100);
    }
}

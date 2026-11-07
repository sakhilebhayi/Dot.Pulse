<?php

namespace App\Livewire\Pulse;

use App\Models\PulseDoor;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use App\Services\Pulse\Lines\ContactGate;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The one way to start a conversation with someone who has not agreed to talk yet: "Knock".
 * A knock carries a context (the offer, role, need or door it is about) and a card of at most four short fields.
 * Used by the Floor, offer and role pages, profiles and doors:
 *
 *   <livewire:pulse.knock-sheet :target="['entity' => $entity->id]" kind="enquiry" context-type="offer" :context-id="$offer->id" />
 *
 * Contract: target is the array LineService::knock takes; kind is one of LineService::KNOCK_KINDS; context-type is
 * offer|role|need|door|null. Dispatches the browser event `pulse-knocked` with the line id when it is sent.
 */
class KnockSheet extends Component
{
    /** @var array<string, int> */
    public array $target = [];

    public string $kind = 'enquiry';

    public ?string $contextType = null;

    public ?int $contextId = null;

    public string $label = 'Knock';

    public bool $open = false;

    public bool $sent = false;

    public ?int $lineId = null;

    public ?string $lineStatus = null;

    public string $what = '';

    public string $whenOrQty = '';

    public string $where = '';

    public string $budgetBand = '';

    public ?string $failure = null;

    /** @param  array<string, int>  $target */
    public function mount(array $target, string $kind = 'enquiry', ?string $contextType = null, ?int $contextId = null, string $label = 'Knock'): void
    {
        $this->target = $target;
        $this->kind = $kind;
        $this->contextType = $contextType;
        $this->contextId = $contextId;
        $this->label = $label;
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

    public function send(LineService $lines): void
    {
        $this->failure = null;

        $this->validate([
            'what' => ['required', 'string', 'max:160'],
            'whenOrQty' => ['nullable', 'string', 'max:120'],
            'where' => ['nullable', 'string', 'max:120'],
            'budgetBand' => ['nullable', 'string', 'max:80'],
        ], [], ['what' => 'what you want', 'whenOrQty' => 'when or how many', 'where' => 'where', 'budgetBand' => 'budget']);

        try {
            $line = $lines->knock(auth()->user(), $this->target, $this->kind, [
                'what' => $this->what,
                'when_or_qty' => $this->whenOrQty,
                'where' => $this->where,
                'budget_band' => $this->budgetBand,
            ], $this->context());
        } catch (LineException $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->sent = true;
        $this->lineId = $line->id;
        $this->lineStatus = $line->status;
        $this->dispatch('pulse-knocked', line: $line->id);
    }

    private function context(): ?Model
    {
        if ($this->contextType === null || $this->contextId === null) {
            return null;
        }

        $class = match ($this->contextType) {
            'offer' => PulseOffer::class,
            'role' => PulseRole::class,
            'need' => PulseNeed::class,
            'door' => PulseDoor::class,
            default => null,
        };

        return $class ? $class::query()->find($this->contextId) : null;
    }

    public function render(): View
    {
        $verdict = $this->open && auth()->check() ? $this->verdict() : null;

        return view('livewire.pulse.knock-sheet', ['verdict' => $verdict]);
    }

    /** @return array<string, mixed>|null */
    private function verdict(): ?array
    {
        $subject = match (true) {
            isset($this->target['entity']) => \App\Models\PulseEntity::query()->find($this->target['entity']),
            isset($this->target['signal']) => \App\Models\PulseSignal::query()->find($this->target['signal']),
            isset($this->target['door']) => PulseDoor::query()->find($this->target['door']),
            isset($this->target['user']) => \App\Models\User::query()->find($this->target['user']),
            default => null,
        };

        return $subject ? app(ContactGate::class)->can(auth()->user(), $subject, $this->context()) : null;
    }
}

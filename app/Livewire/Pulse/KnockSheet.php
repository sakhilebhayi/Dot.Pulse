<?php

namespace App\Livewire\Pulse;

use App\Models\PulseDoor;
use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Lines\ContactGate;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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
 *
 * Added for lists of beats (the Floor, search, saved): `bare` renders no button, only the sheet, and the component
 * listens for the Livewire event `pulse-knock` carrying `spec` (target, kind, contextType, contextId, label, title) so one
 * sheet can serve every beat on a page:
 *
 *   <livewire:pulse.knock-sheet :bare="true" />
 *   wire:click="$dispatch('pulse-knock', { spec: { target: {...}, kind: 'enquiry', ... } })"
 *
 * The sheet is a native dialog: focus is trapped, Escape and the backdrop close it, the page behind is inert.
 */
class KnockSheet extends Component
{
    /** @var array<string, int> */
    #[Locked]
    public array $target = [];

    #[Locked]

    public string $kind = 'enquiry';

    #[Locked]

    public ?string $contextType = null;

    #[Locked]

    public ?int $contextId = null;

    public string $label = 'Knock';

    public bool $open = false;

    public bool $sent = false;

    #[Locked]

    public ?int $lineId = null;

    public ?string $lineStatus = null;

    public string $what = '';

    public string $whenOrQty = '';

    public string $where = '';

    public string $budgetBand = '';

    public ?string $failure = null;

    /** No button: only the sheet, opened by the `pulse-knock` event. */
    public bool $bare = false;

    /** What the knock is about, shown under the title ("Bookkeeping for small shops"). */
    public string $heading = '';

    /** @param  array<string, int>  $target */
    public function mount(array $target = [], string $kind = 'enquiry', ?string $contextType = null, ?int $contextId = null, string $label = 'Knock', bool $bare = false, string $heading = ''): void
    {
        $this->target = $target;
        $this->kind = $kind;
        $this->contextType = $contextType;
        $this->contextId = $contextId;
        $this->label = $label;
        $this->bare = $bare;
        $this->heading = $heading;
    }

    public function openSheet(): void
    {
        $this->fresh();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Point the sheet at another beat and open it. The spec comes from the browser, so nothing in it is trusted:
     * LineService::knock and ContactGate decide again at the moment of the knock.
     *
     * @param  array<string, mixed>  $spec
     */
    #[On('pulse-knock')]
    public function retarget(array $spec): void
    {
        $target = array_filter((array) ($spec['target'] ?? []), fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)));
        $kind = (string) ($spec['kind'] ?? 'enquiry');

        if ($target === [] || ! in_array($kind, LineService::KNOCK_KINDS, true)) {
            return;
        }

        $this->target = array_map('intval', $target);
        $this->kind = $kind;
        $this->contextType = in_array($spec['contextType'] ?? null, ['offer', 'role', 'need', 'door'], true) ? $spec['contextType'] : null;
        $this->contextId = isset($spec['contextId']) ? (int) $spec['contextId'] : null;
        $this->label = mb_substr((string) ($spec['label'] ?? 'Knock'), 0, 24);
        $this->heading = mb_substr((string) ($spec['title'] ?? ''), 0, 160);
        $this->openSheet();
    }

    private function fresh(): void
    {
        $this->reset(['sent', 'lineId', 'lineStatus', 'what', 'whenOrQty', 'where', 'budgetBand', 'failure']);
        $this->resetErrorBag();
    }

    public function send(LineService $lines): void
    {
        $this->failure = null;

        $this->validate([
            'what' => ['required', 'string', 'max:160'],
            'whenOrQty' => ['nullable', 'string', 'max:120'],
            'where' => ['nullable', 'string', 'max:120'],
            'budgetBand' => ['nullable', 'string', 'max:80'],
        ], ['what.required' => 'Say what you want in a few words.'], ['what' => 'what you want', 'whenOrQty' => 'when or how many', 'where' => 'where', 'budgetBand' => 'budget']);

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
        } catch (ValidationException $e) {
            // The service names card fields as card.what; the form calls them what, whenOrQty, where, budgetBand.
            $map = ['card.what' => 'what', 'card.when_or_qty' => 'whenOrQty', 'card.where' => 'where', 'card.budget_band' => 'budgetBand'];
            foreach ($e->errors() as $key => $messages) {
                $this->addError($map[$key] ?? 'what', $messages[0]);
            }

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
        $verdict = $this->open && auth()->check() && ! $this->sent ? $this->verdict() : null;
        $budget = $verdict && ($verdict['allowed'] ?? false) && ($verdict['stranger'] ?? false)
            ? ['left' => app(KnockBudget::class)->remaining(auth()->user())]
            : null;

        return view('livewire.pulse.knock-sheet', ['verdict' => $verdict, 'budget' => $budget]);
    }

    /** @return array<string, mixed>|null */
    private function verdict(): ?array
    {
        $subject = match (true) {
            isset($this->target['entity']) => PulseEntity::query()->find($this->target['entity']),
            isset($this->target['signal']) => PulseSignal::query()->find($this->target['signal']),
            isset($this->target['door']) => PulseDoor::query()->find($this->target['door']),
            isset($this->target['user']) => User::query()->find($this->target['user']),
            default => null,
        };

        return $subject ? app(ContactGate::class)->can(auth()->user(), $subject, $this->context()) : null;
    }
}

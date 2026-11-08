<?php

namespace App\Livewire\Pulse\Lines;

use App\Livewire\Pulse\Lines\Concerns\AnswersRequests;
use App\Livewire\Pulse\Lines\Concerns\HandlesOutcomes;
use App\Livewire\Pulse\Lines\Concerns\HandlesQuotes;
use App\Models\PulseConversation;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use App\Services\Pulse\Entities\MediaService;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\Context;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * One line: the thing it is about on top, the messages, the composer, and what can be done next.
 * Polls every 10 seconds while the page is in view. Every read goes through LineService (present, thread), so an
 * anonymous professional is an alias and a card on the employer's screen and never a name.
 *
 *   <livewire:pulse.lines.thread :conversation="$conversation" />
 */
class Thread extends Component
{
    use AnswersRequests;
    use HandlesOutcomes;
    use HandlesQuotes;
    use WithFileUploads;

    private const IMAGE_RULES = ['image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120']];

    private const IMAGE_MESSAGES = [
        'image.image' => 'Pick a photo: JPEG, PNG or WebP.',
        'image.mimes' => 'Pick a photo: JPEG, PNG or WebP.',
        'image.max' => 'That photo is over 5 MB. Pick a smaller one.',
    ];

    #[Locked]
    public int $conversationId = 0;

    public string $body = '';

    /** @var TemporaryUploadedFile|null */
    public $image = null;

    /** close | reveal while the person is being asked to be sure */
    public ?string $confirming = null;

    #[Locked]
    public ?int $reportingMessage = null;

    public function mount(PulseConversation $conversation, LineService $lines): void
    {
        abort_unless(Gate::forUser(auth()->user())->allows('view', $conversation), 404);

        $this->conversationId = $conversation->id;
        $this->resetSlip();
        $lines->markRead(auth()->user(), $conversation);
    }

    /** The ten second poll: show what is new and mark it read. */
    public function refresh(LineService $lines): void
    {
        $c = $this->line();
        if ($lines->present(auth()->user(), $c)['unread']) {
            $lines->markRead(auth()->user(), $c);
        }
    }

    #[On('line-reported')]
    public function reported(): void
    {
        $this->reportingMessage = null;
    }

    protected function afterAnswer(string $what, int $id): void
    {
        $this->dispatch('toast', message: match ($what) {
            'accepted' => 'They are in. The line is open.',
            'declined' => 'Answered. They are told only what you chose.',
            'ignored' => 'Set aside. It lapses quietly unless you answer.',
            'closed' => 'Closed.',
            'blocked' => 'Blocked. They are not told.',
            default => 'Done.',
        }, kind: 'good');
    }

    // ---------------------------------------------------------------- composer

    public function send(LineService $lines, MediaService $media): void
    {
        $this->failure = null;

        $this->validate([
            'body' => ['nullable', 'string', 'max:'.(int) config('pulse.lines.message_max', 2000)],
        ] + self::IMAGE_RULES, ['body.max' => 'That is over the limit. Split it into two messages.'] + self::IMAGE_MESSAGES);

        $user = auth()->user();
        $c = $this->line();
        $mediaId = null;

        try {
            if ($this->image !== null) {
                $mediaId = $media->store($user, $this->image, 'message', null, $c->id);
            }

            $lines->send($user, $c, $this->body, $mediaId);
        } catch (Throwable $e) {
            if ($mediaId !== null) {
                $media->delete($user, $mediaId);
            }

            if ($e instanceof LineException || $e instanceof ThrottleRequestsException) {
                $this->failure = $e->getMessage();

                return;
            }

            throw $e;
        }

        $this->reset(['body', 'image']);
        $this->dispatch('composer-sent');
    }

    /** Judge a photo the moment it arrives, so a wrong file never reaches the preview. */
    public function updatedImage(): void
    {
        try {
            $this->validateOnly('image', self::IMAGE_RULES, self::IMAGE_MESSAGES);
        } catch (ValidationException $e) {
            $this->image = null;

            throw $e;
        }
    }

    public function removeImage(): void
    {
        $this->image = null;
    }

    // ---------------------------------------------------------------- header actions

    public function toggleMute(LineService $lines): void
    {
        $c = $this->line();
        $lines->muteConversation(auth()->user(), $c, ! $this->pivot('muted'));
        $this->dispatch('toast', message: $this->pivot('muted') ? 'Muted. You will not be notified about this line.' : 'Unmuted.', kind: 'good');
    }

    public function toggleArchive(): void
    {
        $c = $this->line();
        abort_if(Access::side(auth()->user(), $c) === null, 404);

        $this->pivot('archived_at') ? app(LineService::class)->unarchive(auth()->user(), $c) : app(LineService::class)->archive(auth()->user(), $c);

        $this->dispatch('toast', message: $this->pivot('archived_at') ? 'Archived. It will not show in your tabs.' : 'Back in your tabs.', kind: 'good');
    }

    public function ask(string $what): void
    {
        if (in_array($what, ['close', 'reveal'], true)) {
            $this->confirming = $what;
            $this->blocking = null;
            $this->declining = null;
            $this->failure = null;
        }
    }

    public function cancelAsk(): void
    {
        $this->confirming = null;
    }

    public function closeThis(LineService $lines): void
    {
        $this->confirming = null;
        $this->closeLine($this->conversationId, $lines);
    }

    /** Only the invited professional can do this; the service checks. */
    public function reveal(LineService $lines): void
    {
        $this->attempt(function ($user) use ($lines) {
            $lines->reveal($user, $this->line());
            $this->confirming = null;
            $this->dispatch('toast', message: 'Done. They can see who you are now.', kind: 'good');
        });
    }

    public function reportMessage(int $id): void
    {
        $this->reportingMessage = $id;
    }

    public function stopReporting(): void
    {
        $this->reportingMessage = null;
    }

    // ---------------------------------------------------------------- reading

    public function line(): PulseConversation
    {
        $c = PulseConversation::query()->find($this->conversationId);
        abort_unless($c !== null && Gate::forUser(auth()->user())->allows('view', $c), 404);

        return $c;
    }

    /** @return mixed one column of the viewer's per line row (muted, archived_at) */
    protected function pivot(string $column): mixed
    {
        return DB::table('pulse_conversation_user')
            ->where('pulse_conversation_id', $this->conversationId)->where('user_id', auth()->id())
            ->value($column);
    }

    /**
     * The live subject this line is about, if it is still live and the viewer may be told which one it is.
     *
     * @return array{live: bool, url: ?string, label: string}|null null when the line is not about a listing
     */
    private function liveSubject(array $line): ?array
    {
        $type = $line['context']['type'];
        $id = $line['context']['id'];

        if ($id === null || ! in_array($type, ['offer', 'role', 'need'], true)) {
            return null;
        }

        $model = match ($type) {
            'offer' => PulseOffer::query()->with('entity')->find($id),
            'role' => PulseRole::query()->with('entity')->find($id),
            'need' => PulseNeed::query()->find($id),
        };

        if ($model === null || ! Context::isLive($model)) {
            return ['live' => false, 'url' => null, 'label' => ''];
        }

        $url = match ($type) {
            'offer' => pulse_route('offers.show', ['entity' => $model->entity->slug, 'offer' => $model->slug]),
            'role' => pulse_route('roles.show', ['entity' => $model->entity->slug, 'role' => $model->slug]),
            'need' => pulse_route('needs.show', ['need' => $model->id]),
        };

        return ['live' => true, 'url' => $url === '#' ? null : $url, 'label' => 'See the live '.$type];
    }

    public function render(LineService $lines): View
    {
        $user = auth()->user();
        $c = $this->line();
        $line = $lines->present($user, $c);
        $messages = $lines->thread($user, $c);

        $group = $line['quote_group'];
        $siblings = $group !== null ? PulseConversation::query()->where('quote_group', $group)->count() : 0;

        return view('livewire.pulse.lines.thread', [
            'line' => $line,
            'messages' => $messages,
            'model' => $c,
            'quotes' => $this->quotesFor($c),
            'compareUrl' => $siblings > 1 ? route('lines.quotes', $group) : null,
            'subject' => $this->liveSubject($line),
            'muted' => (bool) $this->pivot('muted'),
            'archived' => $this->pivot('archived_at') !== null,
            'outcomeState' => $this->outcomeState($c, $line),
            'reviewState' => $this->reviewState($c, $line),
            'slipOpen' => $this->canRespond($c),
            'limit' => (int) config('pulse.lines.message_max', 2000),
        ]);
    }
}

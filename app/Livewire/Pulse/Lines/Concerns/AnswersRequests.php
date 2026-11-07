<?php

namespace App\Livewire\Pulse\Lines\Concerns;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\User;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * The actions on a request that are the same in the inbox and on a line's own page: say yes, say not now (with a
 * short honest reason), let it lapse quietly, withdraw, block. Authorisation lives in the services and policies;
 * here a line a person cannot see is a 404, and a refusal is shown in plain words, never as an error page.
 *
 * Needs the host to declare `afterAnswer(string $what, int $id)` and to have a `?string $failure` it renders.
 */
trait AnswersRequests
{
    public ?string $failure = null;

    /** The request the person is choosing a "not now" reason for. */
    #[Locked]
    public ?int $declining = null;

    public string $declineReason = 'not_now';

    /** The line whose block is waiting for a confirm. */
    #[Locked]
    public ?int $blocking = null;

    abstract protected function afterAnswer(string $what, int $id): void;

    public function accept(int $id, LineService $lines): void
    {
        $this->attempt(function (User $user) use ($id, $lines) {
            $lines->accept($user, $this->lineFor($user, $id));
            $this->afterAnswer('accepted', $id);
        });
    }

    public function chooseDecline(int $id): void
    {
        $this->lineFor(auth()->user(), $id);
        $this->declining = $id;
        $this->declineReason = 'not_now';
        $this->blocking = null;
        $this->failure = null;
    }

    public function cancelDecline(): void
    {
        $this->declining = null;
    }

    public function decline(LineService $lines): void
    {
        $id = $this->declining;
        if ($id === null) {
            return;
        }

        $this->attempt(function (User $user) use ($id, $lines) {
            $lines->decline($user, $this->lineFor($user, $id), $this->declineReason);
            $this->declining = null;
            $this->afterAnswer('declined', $id);
        });
    }

    public function ignore(int $id, LineService $lines): void
    {
        $this->attempt(function (User $user) use ($id, $lines) {
            $lines->ignoreQuietly($user, $this->lineFor($user, $id));
            $this->afterAnswer('ignored', $id);
        });
    }

    /** Withdraw a request you sent, or close an open line. */
    public function closeLine(int $id, LineService $lines): void
    {
        $this->attempt(function (User $user) use ($id, $lines) {
            $lines->close($user, $this->lineFor($user, $id));
            $this->afterAnswer('closed', $id);
        });
    }

    public function askBlock(int $id): void
    {
        $this->lineFor(auth()->user(), $id);
        $this->blocking = $id;
        $this->declining = null;
        $this->failure = null;
    }

    public function cancelBlock(): void
    {
        $this->blocking = null;
    }

    public function confirmBlock(LineService $lines, BlockService $blocks): void
    {
        $id = $this->blocking;
        if ($id === null) {
            return;
        }

        $this->attempt(function (User $user) use ($id, $lines, $blocks) {
            $c = $this->lineFor($user, $id);
            $line = $lines->present($user, $c);
            $counterpart = $line['counterpart'];
            $side = $line['side'];

            if ($counterpart['kind'] === 'entity') {
                $blocks->block($user, PulseEntity::query()->findOrFail($counterpart['entity_id']));
            } elseif ($counterpart['kind'] === 'person') {
                $mine = $side === 'recipient' ? $c->entity_id : $c->initiator_entity_id;
                $person = User::query()->findOrFail($counterpart['user_id']);

                if ($mine !== null && Access::canRespondFor($user, $mine)) {
                    $blocks->blockAsEntity($user, PulseEntity::query()->findOrFail($mine), $person);
                } else {
                    $blocks->block($user, $person);
                }
            } else {
                throw LineException::because('invalid', 'You cannot block someone whose name you have not been shown. Close the line instead.');
            }

            $this->blocking = null;
            $this->afterAnswer('blocked', $id);
        });
    }

    /** True when there is someone on the other side that can be blocked by name. */
    protected function blockable(array $line): bool
    {
        return in_array($line['counterpart']['kind'] ?? null, ['person', 'entity'], true);
    }

    /** A line the person is on (or may review for a report), or a 404. */
    protected function lineFor(User $user, int $id): PulseConversation
    {
        $c = PulseConversation::query()->find($id);
        abort_unless($c !== null && Gate::forUser($user)->allows('view', $c), 404);

        return $c;
    }

    /** Run an action; show a refusal in plain words instead of an error page. */
    protected function attempt(Closure $action): void
    {
        $this->failure = null;

        try {
            $action(auth()->user());
        } catch (LineException $e) {
            $this->failure = $e->getMessage();
        } catch (ThrottleRequestsException $e) {
            $this->failure = $e->getMessage();
        }
    }
}

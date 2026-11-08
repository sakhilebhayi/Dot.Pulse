<?php

namespace App\Livewire\Pulse\Me;

use App\Http\Controllers\Pulse\PrivacyExportController;
use App\Models\PulseBlock;
use App\Models\PulseEntity;
use App\Models\PulseEntityBlock;
use App\Models\PulseEntityMember;
use App\Models\PulseProfile;
use App\Models\User;
use App\Services\Pulse\Discovery\Taxonomy;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\ConsentLedger;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\MuteService;
use App\Services\Pulse\Lines\PrivacyEraser;
use App\Services\Pulse\Lines\StepUp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "Me, Privacy": every permission Pulse relies on in plain words with a one tap withdraw (D24), who you blocked and
 * muted, the data download, and erasing what Pulse holds about you.
 */
class Privacy extends Component
{
    public const PHRASE = 'erase my data';

    public string $confirmation = '';

    /** The emailed code, when the step needs one (StepUp::codeRequired). */
    public string $code = '';

    public bool $exportOpen = false;

    public bool $codeSent = false;

    public ?string $problem = null;

    public bool $eraseOpen = false;

    public function withdraw(string $key): void
    {
        $this->problem = null;

        try {
            app(ConsentLedger::class)->withdraw(auth()->user(), $key);
            $this->dispatch('toast', message: 'Withdrawn.', kind: 'good');
        } catch (LineException $e) {
            $this->problem = $e->getMessage();
        } catch (AuthorizationException) {
            $this->problem = 'That is not yours to withdraw.';
        }
    }

    public function unblockUser(int $id): void
    {
        $user = User::query()->find($id);

        if ($user && PulseBlock::query()->where('blocker_id', auth()->id())->where('blocked_id', $id)->exists()) {
            app(BlockService::class)->unblock(auth()->user(), $user);
            $this->dispatch('toast', message: 'Unblocked. Closed lines stay closed.', kind: 'good');
        }
    }

    public function unblockEntity(int $id): void
    {
        $entity = PulseEntity::query()->find($id);

        if ($entity && PulseEntityBlock::query()->where('entity_id', $id)->where('user_id', auth()->id())->where('side', PulseEntityBlock::BY_USER)->exists()) {
            app(BlockService::class)->unblock(auth()->user(), $entity);
            $this->dispatch('toast', message: 'Unblocked.', kind: 'good');
        }
    }

    public function unmute(string $kind, string $target): void
    {
        try {
            app(MuteService::class)->unmute(auth()->user(), $kind, $target);
        } catch (LineException) {
            return;
        }
    }

    public function startErase(): void
    {
        $this->problem = null;
        $this->confirmation = '';
        $this->code = '';
        $this->codeSent = false;
        $this->eraseOpen = true;
    }

    public function cancelErase(): void
    {
        $this->eraseOpen = false;
        $this->confirmation = '';
        $this->code = '';
    }

    public function codeRequired(): bool
    {
        return StepUp::codeRequired();
    }

    /** Email the 6 digit code for "export" or "erase". */
    public function sendCode(string $action, StepUp $stepUp): void
    {
        $this->resetErrorBag('code');
        abort_unless(in_array($action, [StepUp::EXPORT, StepUp::ERASE], true), 404);

        if (! StepUp::codeRequired()) {
            return;
        }

        try {
            $stepUp->sendCode(auth()->user(), $action);
            $this->codeSent = true;
            $this->dispatch('toast', message: 'Code sent. Check your email. It works for 10 minutes.', kind: 'good');
        } catch (ValidationException $e) {
            $this->addError('code', collect($e->errors())->flatten()->first());
        }
    }

    public function startExport(): void
    {
        $this->resetErrorBag();
        $this->confirmation = '';
        $this->code = '';
        $this->codeSent = false;
        $this->exportOpen = true;
    }

    public function cancelExport(): void
    {
        $this->exportOpen = false;
        $this->confirmation = '';
        $this->code = '';
    }

    /** Confirmed: allow the download for the next five minutes and send the browser to it. */
    public function export(StepUp $stepUp): mixed
    {
        $this->resetErrorBag();

        if ($problem = $stepUp->problem(auth()->user(), StepUp::EXPORT, $this->confirmation, $this->code)) {
            $this->addError(mb_strtolower(trim($this->confirmation)) === StepUp::phrase(StepUp::EXPORT) ? 'code' : 'confirmation', $problem);

            return null;
        }

        session()->put(PrivacyExportController::GRANT, now()->addMinutes(5)->getTimestamp());
        $this->exportOpen = false;
        $this->confirmation = '';
        $this->code = '';

        return $this->redirect(route('me.export'), navigate: false);
    }

    public function erase(): mixed
    {
        $this->problem = null;
        $user = auth()->user();

        if ($problem = app(StepUp::class)->problem($user, StepUp::ERASE, $this->confirmation, $this->code)) {
            $this->addError(mb_strtolower(trim($this->confirmation)) === self::PHRASE ? 'code' : 'confirmation', $problem);

            return null;
        }

        $stuck = PulseEntityMember::query()->where('user_id', $user->id)->where('role', 'owner')->with('entity:id,name')->get()
            ->filter(fn ($m) => PulseEntityMember::query()->where('entity_id', $m->entity_id)->where('role', 'owner')->count() <= 1)
            ->map(fn ($m) => $m->entity?->name)->filter()->values();

        if ($stuck->isNotEmpty()) {
            $this->problem = 'You are the only owner of '.$stuck->implode(', ').'. Hand it to someone else, or archive it, before you erase your data.';

            return null;
        }

        PrivacyEraser::erase($user);

        // Start from the top if they ever come back: the age declaration and the welcome flow are theirs to redo.
        PulseProfile::query()->where('user_id', $user->id)->update(['onboarded_at' => null, 'age_confirmed_at' => null, 'avatar_media_id' => null]);

        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        return $this->redirect('/', navigate: false);
    }

    /** @return list<array<string, mixed>> */
    public function ledger(): array
    {
        $user = auth()->user();

        return app(ConsentLedger::class)->entries($user);
    }

    /** @return array{people: Collection, pages: Collection} */
    public function blocks(): array
    {
        $me = auth()->id();

        return [
            'people' => PulseBlock::query()->where('blocker_id', $me)->with('blocked:id,name')->latest('id')->get()
                ->map(fn ($b) => ['id' => $b->blocked_id, 'label' => $this->personLabel($b->blocked_id, $b->blocked?->name)]),
            'pages' => PulseEntityBlock::query()->where('user_id', $me)->where('side', PulseEntityBlock::BY_USER)->with('entity:id,name')->latest('id')->get(),
        ];
    }

    /** @return list<array{kind: string, target: string, label: string}> */
    public function mutes(): array
    {
        return app(MuteService::class)->all(auth()->user())->map(function ($m) {
            $label = match ($m->kind) {
                'topic' => Taxonomy::label($m->target),
                'user' => is_numeric($m->target) ? $this->personLabel((int) $m->target, User::query()->whereKey((int) $m->target)->value('name')) : $m->target,
                'entity' => (is_numeric($m->target) ? PulseEntity::query()->whereKey((int) $m->target)->value('name') : PulseEntity::query()->where('slug', $m->target)->value('name')) ?? $m->target,
                default => $m->target,
            };

            return ['kind' => $m->kind, 'target' => $m->target, 'label' => $label];
        })->all();
    }

    /** A person who is anonymous to employers is never named here, not even to the person who blocked them. */
    private function personLabel(int $id, ?string $name): string
    {
        $anonymous = PulseProfile::query()->where('user_id', $id)->where('discoverability', 'anonymous')->exists();

        return $anonymous ? 'An anonymous professional' : ($name ?: 'A person');
    }

    public function render(): View
    {
        return view('livewire.pulse.me.privacy');
    }
}

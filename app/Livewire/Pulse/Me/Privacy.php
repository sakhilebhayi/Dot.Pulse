<?php

namespace App\Livewire\Pulse\Me;

use App\Models\PulseBlock;
use App\Models\PulseEntity;
use App\Models\PulseEntityBlock;
use App\Models\PulseEntityMember;
use App\Models\PulseNotificationPref;
use App\Models\PulseProfile;
use App\Models\User;
use App\Services\Pulse\Discovery\Taxonomy;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\ConsentLedger;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\MuteService;
use App\Services\Pulse\Lines\NotificationPrefs;
use App\Services\Pulse\Lines\PrivacyEraser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
        $this->eraseOpen = true;
    }

    public function cancelErase(): void
    {
        $this->eraseOpen = false;
        $this->confirmation = '';
    }

    public function erase(): mixed
    {
        $this->problem = null;
        $user = auth()->user();

        if (mb_strtolower(trim($this->confirmation)) !== self::PHRASE) {
            $this->addError('confirmation', 'Type "'.self::PHRASE.'" exactly to go on.');

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

        // ConsentLedger reads preferences through NotificationPrefs::get(), which creates a row that has the digest
        // switched on. Pulse's digest is opt-in, so make the row first, with it off, before the ledger looks.
        if (! PulseNotificationPref::query()->where('user_id', $user->id)->exists()) {
            $prefs = app(NotificationPrefs::class);
            PulseNotificationPref::query()->create(['user_id' => $user->id] + array_replace($prefs->defaults(), ['digest' => false]));
        }

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

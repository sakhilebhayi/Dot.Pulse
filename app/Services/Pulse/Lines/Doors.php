<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseEntity;
use App\Models\PulseProfile;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Entities\AnonymousGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open doors (D27). A person or a business page opens a door for a day or a week with a stated purpose ("looking
 * for a baker for Saturday"). Strangers may knock once with a context card; the owner accepts or lets it lapse,
 * and contact details unlock only on accept. A door is a public signal (kind `door`, subject type PulseDoor), so
 * its purpose is public text: no links, no contact details. One open door per owner, a limited number of knocks.
 */
class Doors
{
    public const LENGTHS = ['day' => 1, 'week' => 7];

    public function __construct(private readonly LineService $lines, private readonly TextGuard $guard) {}

    /**
     * @param  User|PulseEntity  $owner  the person, or the page; for a page `$by` names the responder opening it
     */
    public function open(User|PulseEntity $owner, string $purpose, string $length, ?User $by = null): PulseSignal
    {
        $by ??= $owner instanceof User ? $owner : null;

        if ($by === null) {
            throw new AuthorizationException('Say who is opening the door.');
        }

        if ($owner instanceof PulseEntity) {
            if (! Access::canRespondFor($by, $owner)) {
                throw new AuthorizationException('You do not answer for that page.');
            }
            if ($owner->status !== 'live') {
                throw LineException::because('not_allowed', 'This page is not live yet.');
            }
        } elseif (! $owner->is($by)) {
            throw new AuthorizationException('You can only open your own door.');
        } elseif (AnonymousGuard::isAnonymous($owner)) {
            // A door carries your name and your place, which would join it to your anonymous card.
            throw LineException::because('anonymous', AnonymousGuard::MESSAGE.' A door carries your name.');
        }

        if (! isset(self::LENGTHS[$length])) {
            throw ValidationException::withMessages(['length' => 'A door stays open for a day or a week.']);
        }

        if (! Access::ageConfirmed($by)) {
            throw LineException::because('age', 'Pulse is for people 18 and over. Confirm your age in your profile first.');
        }

        $purpose = $this->guard->line($purpose, 400);
        if (mb_strlen($purpose) < 8 || mb_strlen($purpose) > 160) {
            throw ValidationException::withMessages(['purpose' => 'Say what the door is for in 8 to 160 characters.']);
        }

        if ($this->guard->violations($purpose) !== []) {
            throw ValidationException::withMessages(['purpose' => 'A door is public. Leave links and contact details out.']);
        }

        $verdict = $this->guard->assertNotBlocked($purpose, $by, 'purpose', ['surface' => 'door', 'public' => true]);

        $isUser = $owner instanceof User;
        $policy = $isUser
            ? (PulseProfile::query()->where('user_id', $owner->id)->value('contact_policy') ?: 'context')
            : ($owner->contact_policy ?: 'anyone');

        if ($policy === 'nobody') {
            throw LineException::because('not_allowed', 'Your contact setting is "nobody". Change it before opening a door.');
        }

        $existing = PulseDoor::query()->open()->where($isUser ? 'owner_id' : 'entity_id', $owner->id)->exists();
        if ($existing) {
            throw LineException::because('exists', 'You already have a door open. Close it before opening another.');
        }

        return DB::transaction(function () use ($owner, $by, $purpose, $length, $verdict, $isUser) {
            $door = PulseDoor::create([
                'owner_id' => $isUser ? $owner->id : null,
                'entity_id' => $isUser ? null : $owner->id,
                'opened_by' => $by->id,
                'purpose' => $purpose,
                'length' => $length,
                'status' => PulseDoor::OPEN,
                'opens_at' => now(),
                'closes_at' => now()->addDays(self::LENGTHS[$length]),
                'knock_cap' => (int) config('pulse.lines.door_knock_cap', 10),
            ]);

            $signal = PulseSignal::create([
                'kind' => 'door',
                'subject_type' => $door->getMorphClass(),
                'subject_id' => $door->id,
                'owner_id' => $isUser ? $owner->id : null,
                'entity_id' => $isUser ? null : $owner->id,
                'title' => $purpose,
                'excerpt' => 'Open for '.($length === 'day' ? '24 hours' : 'a week').'. Knock once with a card.',
                'topics' => [],
                'skills' => [],
                'place_id' => $isUser ? PulseProfile::query()->where('user_id', $owner->id)->value('place_id') : $owner->place_id,
                'reach' => 'region',
                'is_remote' => false,
                'anonymous' => false,
                'quality' => 0.5,
                'status' => $verdict['verdict'] === 'hold' ? 'held' : 'live',
                'published_at' => now(),
                'expires_at' => $door->closes_at,
            ]);

            $door->forceFill(['signal_id' => $signal->id])->save();
            AuditLog::record($by, 'door.open', $door, ['length' => $length, 'owner' => $isUser ? 'user' : 'entity']);

            return $signal;
        });
    }

    public function close(User $by, PulseDoor|PulseSignal $door): void
    {
        $door = $this->door($door);

        $allowed = ($door->owner_id !== null && $door->owner_id === $by->id)
            || ($door->entity_id !== null && Access::canRespondFor($by, $door->entity_id));

        if (! $allowed) {
            throw new AuthorizationException('That is not your door.');
        }

        $this->end($door, PulseDoor::CLOSED);
        AuditLog::record($by, 'door.close', $door);
    }

    /** A stranger knocks once. This is LineService::knock with the door as the target and the context. */
    public function knockOnDoor(User $from, PulseDoor|PulseSignal $door, array $card): PulseConversation
    {
        $door = $this->door($door);

        return $this->lines->knock($from, ['door' => $door->id], 'intro', $card, $door);
    }

    /** Doors whose time is up. Returns how many lapsed. */
    public function lapse(): int
    {
        $due = PulseDoor::query()->where('status', PulseDoor::OPEN)->where('closes_at', '<=', now())->get();

        foreach ($due as $door) {
            $this->end($door, PulseDoor::LAPSED);
        }

        return $due->count();
    }

    private function end(PulseDoor $door, string $status): void
    {
        $door->forceFill(['status' => $status])->save();

        if ($door->signal_id) {
            PulseSignal::query()->whereKey($door->signal_id)->update(['status' => 'expired', 'updated_at' => now()]);
        }
    }

    private function door(PulseDoor|PulseSignal $door): PulseDoor
    {
        if ($door instanceof PulseSignal) {
            return PulseDoor::query()->where('signal_id', $door->id)->firstOrFail();
        }

        return $door;
    }
}

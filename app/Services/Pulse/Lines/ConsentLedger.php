<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseEntityMember;
use App\Models\PulseProfile;
use App\Models\PulseQuote;
use App\Models\PulseSignal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The consent ledger (D24): every permission Pulse relies on, in plain words, built from the person's real
 * settings and open lines, each with one tap to withdraw. Nothing here is stored separately, so it cannot drift
 * from what the product actually does. withdraw() performs the action behind a row.
 *
 * A row: key (the withdraw action and its id, e.g. "door.close:7"), kind, sentence, since, until, and
 * withdraw {action, label}.
 */
class ConsentLedger
{
    public function __construct(
        private readonly NotificationPrefs $prefs,
        private readonly Doors $doors,
        private readonly LineService $lines,
    ) {}

    /**
     * @return list<array{key: string, kind: string, sentence: string, since: ?\Carbon\Carbon, until: ?\Carbon\Carbon, withdraw: array{action: string, label: string}}>
     */
    public function entries(User $u): array
    {
        $rows = [];
        $profile = PulseProfile::query()->where('user_id', $u->id)->first();

        // Who can find you.
        if ($profile && $profile->discoverability === 'anonymous') {
            $rows[] = $this->row('discoverability.off', 'discoverability', 'Employers on Pulse can find you by your skills, experience band and region. They cannot see your name until you reveal it.', $profile->updated_at, null, 'Stop employers finding me');
        } elseif ($profile && $profile->discoverability === 'open') {
            $rows[] = $this->row('discoverability.off', 'discoverability', 'Employers on Pulse can find you by name, with your skills and region.', $profile->updated_at, null, 'Stop employers finding me');
        }

        // Who can message you.
        $policy = $profile?->contact_policy ?: 'context';
        if ($policy === 'anyone') {
            $rows[] = $this->row('contact_policy.requests', 'contact_policy', 'Anyone on Pulse can message you directly.', null, null, 'Only let people send requests');
        } elseif ($policy === 'context') {
            $rows[] = $this->row('contact_policy.nobody', 'contact_policy', 'People can contact you only about something of yours that is live: a need, a door or your talent card. They send a request and you choose whether to reply.', null, null, 'Stop all new contact');
        } elseif ($policy === 'requests') {
            $rows[] = $this->row('contact_policy.nobody', 'contact_policy', 'Anyone on Pulse can send you a request. Nothing reaches you until you accept it.', null, null, 'Stop all new contact');
        }

        // What Pulse emails you.
        $pref = $this->prefs->get($u);
        if ($pref->digest && $pref->frequency !== 'off') {
            $rows[] = $this->row('digest.off', 'digest', 'Pulse emails you a summary at '.$pref->digest_hour.' ('.$pref->timezone.') when there is something new for you.', null, null, 'Stop the summary email');
        }
        if (($pref->channels['email'] ?? true) && $pref->frequency !== 'off') {
            $rows[] = $this->row('email.off', 'email', 'Pulse emails you when a conversation, quote or outcome needs you ('.$pref->frequency.').', null, null, 'Stop emails from Pulse');
        }

        // Pages you speak for.
        $memberships = PulseEntityMember::query()->where('user_id', $u->id)->with('entity:id,name')->get();
        foreach ($memberships as $m) {
            $rows[] = $this->row('entity.leave:'.$m->entity_id, 'entity', 'You answer for '.$m->entity->name.' as '.$m->role.'. The page can show people it contacts that you speak for it.', $m->created_at, null, 'Leave '.$m->entity->name);
        }

        // Doors you opened.
        $doors = PulseDoor::query()->open()->where('owner_id', $u->id)->get();
        foreach ($doors as $door) {
            $rows[] = $this->row('door.close:'.$door->id, 'door', 'Your door "'.$door->purpose.'" is open until '.$door->closes_at->format('D j M, H:i').'. Strangers can knock once with a card.', $door->opens_at, $door->closes_at, 'Close the door');
        }

        // Who may message you about what, right now.
        $rows = array_merge($rows, $this->lineRows($u));

        return $rows;
    }

    /** Perform the withdraw action behind a ledger row. */
    public function withdraw(User $u, string $key): void
    {
        [$action, $id] = array_pad(explode(':', $key, 2), 2, null);

        match ($action) {
            'discoverability.off' => $this->setProfile($u, ['discoverability' => 'off']),
            'contact_policy.requests' => $this->setProfile($u, ['contact_policy' => 'requests']),
            'contact_policy.nobody' => $this->setProfile($u, ['contact_policy' => 'nobody']),
            'digest.off' => $this->prefs->update($u, ['digest' => false]),
            'email.off' => $this->prefs->update($u, ['channels' => ['email' => false]]),
            'entity.leave' => $this->leaveEntity($u, (int) $id),
            'door.close' => $this->doors->close($u, PulseDoor::query()->findOrFail((int) $id)),
            'line.close' => $this->lines->close($u, PulseConversation::query()->findOrFail((int) $id)),
            default => throw LineException::because('invalid', 'That permission cannot be withdrawn here.'),
        };

        AuditLog::record($u, 'consent.withdraw', null, ['action' => $action]);
    }

    /** @return list<array<string, mixed>> */
    private function lineRows(User $u): array
    {
        $rows = [];

        $lines = PulseConversation::query()
            ->where('status', PulseConversation::OPEN)
            ->where(fn ($q) => $q->where('recipient_id', $u->id)->orWhere(fn ($w) => $w->where('initiator_id', $u->id)->where('kind', 'quote')))
            ->with(['initiator:id,name', 'initiatorEntity:id,name', 'entity:id,name'])
            ->latest('id')->limit(50)->get();

        $quotes = PulseQuote::query()->live()->whereIn('pulse_conversation_id', $lines->pluck('id'))->get()->keyBy('pulse_conversation_id');

        foreach ($lines as $c) {
            $about = $c->context_title ?: 'this conversation';

            if ($c->recipient_id === $u->id) {
                $who = $c->initiatorEntity?->name ?? $c->initiator?->name ?? 'Someone';
                $rows[] = $this->row('line.close:'.$c->id, 'line', "{$who} may message you about {$about}.", $c->first_response_at, null, 'Close this line');
            } else {
                $quote = $quotes[$c->id] ?? null;
                $until = $quote?->valid_until?->copy()->endOfDay();
                $who = $c->entity?->name ?? 'The business';
                $rows[] = $this->row('line.close:'.$c->id, 'line', "{$who} may message you about your quote".($until ? ' until '.$until->format('j M') : '').'.', $c->first_response_at, $until, 'Close this line');
            }
        }

        return $rows;
    }

    private function row(string $key, string $kind, string $sentence, $since, $until, string $label): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'sentence' => $sentence,
            'since' => $since,
            'until' => $until,
            'withdraw' => ['action' => explode(':', $key)[0], 'label' => $label],
        ];
    }

    private function setProfile(User $u, array $attrs): void
    {
        PulseProfile::query()->where('user_id', $u->id)->update($attrs + ['updated_at' => now()]);

        if (($attrs['discoverability'] ?? null) === 'off') {
            // A talent card must not stay findable once the person withdrew.
            PulseSignal::query()->where('owner_id', $u->id)->where('kind', 'talent')->update(['status' => 'paused', 'updated_at' => now()]);
        }
    }

    private function leaveEntity(User $u, int $entityId): void
    {
        $member = PulseEntityMember::query()->where('entity_id', $entityId)->where('user_id', $u->id)->first();

        if (! $member) {
            throw new AuthorizationException('You are not part of that page.');
        }

        if ($member->role === 'owner' && PulseEntityMember::query()->where('entity_id', $entityId)->where('role', 'owner')->count() <= 1) {
            throw LineException::because('not_allowed', 'You are the only owner. Hand the page to someone else before you leave.');
        }

        DB::table('pulse_entity_members')->where('id', $member->id)->delete();
    }
}

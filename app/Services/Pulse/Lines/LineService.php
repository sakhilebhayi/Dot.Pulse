<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\PulseMessage;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Lines: first contact, requests and conversations (D8, D9, D18, D19, D21, D27).
 *
 * A first contact is a request that carries what it is about, copied at knock time, and an enquiry card of at most
 * four plain text fields. Links and contact details are refused until the other side has replied. The recipient
 * accepts, declines (the sender hears a short honest reason, default "Not now"), lets it lapse quietly, or blocks.
 * A request nobody answers in 14 days closes as expired and the sender is told (see SweepLines). Spending the
 * knock budget, creating the line and writing its first message happen in one transaction.
 *
 * Reads for a screen go through present(), thread() and inbox(): they hide the identity of an anonymous
 * professional from the page that invited them until reveal().
 */
class LineService
{
    /** Kinds a person can knock with. Quote requests go through QuoteService. */
    public const KNOCK_KINDS = ['dm', 'enquiry', 'application', 'intro', 'collab', 'invite'];

    private const CARD_FIELDS = ['what' => 200, 'when_or_qty' => 80, 'where' => 80, 'budget_band' => 40];

    public function __construct(
        private readonly ContactGate $gate,
        private readonly KnockBudget $budget,
        private readonly Notifier $notifier,
        private readonly TextGuard $guard,
        private readonly LinePresenter $presenter,
    ) {}

    // ---------------------------------------------------------------- first contact

    /**
     * @param  array{user?: int, entity?: int, signal?: int, door?: int, as_entity?: int}  $target
     *                                                                                              An anonymous professional is addressed by `signal` (their talent signal), never by user id.
     *                                                                                              A talent invite also names `as_entity`: the employer page the sender answers for.
     * @param  array{what?: string, when_or_qty?: string, where?: string, budget_band?: string}  $card
     */
    public function knock(User $from, array $target, string $kind, array $card, ?Model $context = null): PulseConversation
    {
        if (! in_array($kind, self::KNOCK_KINDS, true)) {
            throw LineException::because('invalid', 'That is not a kind of line you can start this way.');
        }

        Throttle::hit($from, 'knock');

        $t = $this->resolveTarget($from, $target, $kind);
        $card = $this->cleanCard($from, $card);
        $context ??= $t['context'];

        if ($kind === 'application' && ! ($context instanceof PulseRole)) {
            throw LineException::because('invalid', 'Applications start from a role. Open the role and apply from there.');
        }

        $decision = $this->gate->can($from, $t['gate'], $context);
        if (! $decision['allowed']) {
            throw LineException::because($decision['code'] === 'budget' ? 'budget' : ($decision['code'] === 'age' ? 'age' : 'not_allowed'), $decision['why']);
        }

        if ($existing = $this->findActive($from, $t, $kind, $context)) {
            return $existing;
        }

        $this->assertNotRecentlyDeclined($from, $t, $context);

        $described = $context ? Context::describe($context) : null;
        $status = ($decision['mode'] === 'direct' && $kind !== 'invite') ? PulseConversation::OPEN : PulseConversation::REQUESTED;

        $conversation = DB::transaction(function () use ($from, $t, $kind, $card, $decision, $described, $status) {
            if ($t['door'] !== null) {
                $this->takeDoorSlot($from, $t['door']);
            }

            if ($decision['stranger']) {
                $this->budget->spend($from);
            }

            $c = PulseConversation::create([
                'type' => 'direct',
                'kind' => $kind,
                'status' => $status,
                'initiator_id' => $from->id,
                'recipient_id' => $t['user']?->id,
                'entity_id' => $t['entity']?->id,
                'initiator_entity_id' => $t['as_entity']?->id,
                'door_id' => $t['door']?->id,
                'context_type' => $described['type'] ?? null,
                'context_id' => $described['id'] ?? null,
                'context_title' => $described ? mb_substr($described['title'], 0, 160) : null,
                'context_snapshot' => $t['hidden'] && $t['signal'] ? ['anonymous' => true] + Context::anonymousCard($t['signal']) : ($described['snapshot'] ?? null),
                'card' => $card,
                'identity_hidden' => $t['hidden'],
                'last_message_at' => now(),
                'last_message_side' => 'initiator',
                'initiator_read_at' => now(),
            ]);

            $this->addParticipants($c);
            $this->writeMessage($c, $from, 'initiator', $this->composeCard($card), 'text', ['card' => $card]);

            AuditLog::record($from, 'line.knock', $c, ['kind' => $kind, 'mode' => $decision['mode'], 'context' => $described['type'] ?? null]);

            return $c;
        });

        $this->loadParties($conversation);
        $this->notifyRequest($conversation, $from);

        return $conversation;
    }

    /** A plain direct message line, only when the contact policy says direct. No first message is written. */
    public function open(User $from, Model $target): PulseConversation
    {
        $decision = $this->gate->can($from, $target);

        if (! $decision['allowed'] || $decision['mode'] !== 'direct') {
            throw LineException::because($decision['allowed'] ? 'not_allowed' : ($decision['code'] === 'budget' ? 'budget' : 'not_allowed'),
                $decision['allowed'] ? 'They take requests, not direct messages. Send a request with a card.' : $decision['why']);
        }

        $user = $target instanceof User ? $target : null;
        $entity = $target instanceof PulseEntity ? $target : null;

        $existing = PulseConversation::query()
            ->where('kind', 'dm')->where('status', PulseConversation::OPEN)
            ->where('initiator_id', $from->id)
            ->when($user, fn ($q) => $q->where('recipient_id', $user->id))
            ->when($entity, fn ($q) => $q->where('entity_id', $entity->id))
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($from, $user, $entity, $decision) {
            if ($decision['stranger']) {
                $this->budget->spend($from);
            }

            $c = PulseConversation::create([
                'type' => 'direct',
                'kind' => 'dm',
                'status' => PulseConversation::OPEN,
                'initiator_id' => $from->id,
                'recipient_id' => $user?->id,
                'entity_id' => $entity?->id,
                'initiator_read_at' => now(),
            ]);

            $this->addParticipants($c);
            AuditLog::record($from, 'line.open', $c, ['kind' => 'dm']);

            return $c;
        });
    }

    // ---------------------------------------------------------------- answering

    public function accept(User $by, PulseConversation $c): void
    {
        Gate::forUser($by)->authorize('accept', $c);

        $moved = PulseConversation::query()->whereKey($c->id)->where('status', PulseConversation::REQUESTED)->update([
            'status' => PulseConversation::OPEN,
            'first_response_at' => now(),
            'recipient_read_at' => now(),
            'ignored_at' => null,
            'updated_at' => now(),
        ]);

        if ($moved === 0) {
            throw LineException::because('not_open', 'That request has already been answered.');
        }

        $c->refresh();
        $this->writeMessage($c, $by, 'recipient', 'Request accepted. Contact details can be shared now.', 'system', ['event' => 'accepted']);
        $this->refillSender($c);
        AuditLog::record($by, 'line.accept', $c);

        $this->notifySide($c, 'initiator', $by, [
            'type' => 'line_accepted',
            'title' => $this->partyName($c, 'recipient').' accepted your request',
            'body' => $this->about($c),
        ], 'lines');
    }

    public function decline(User $by, PulseConversation $c, ?string $reasonCode = null): void
    {
        Gate::forUser($by)->authorize('decline', $c);

        $reasons = (array) config('pulse.lines.decline_reasons', []);
        $code = $reasonCode !== null && array_key_exists($reasonCode, $reasons) ? $reasonCode : 'not_now';

        $moved = PulseConversation::query()->whereKey($c->id)->where('status', PulseConversation::REQUESTED)->update([
            'status' => PulseConversation::DECLINED,
            'decline_reason' => $code,
            'closed_reason' => 'declined',
            'first_response_at' => now(),
            'closed_at' => now(),
            'updated_at' => now(),
        ]);

        if ($moved === 0) {
            throw LineException::because('not_open', 'That request has already been answered.');
        }

        $c->refresh();
        AuditLog::record($by, 'line.decline', $c, ['reason' => $code]);

        $this->notifySide($c, 'initiator', $by, [
            'type' => 'line_declined',
            'title' => $this->partyName($c, 'recipient').' replied: '.($reasons[$code] ?? 'Not now'),
            'body' => $this->about($c),
        ], 'lines');
    }

    /** Let it lapse without telling the sender. It still closes as expired after 14 days, and the sender is told then. */
    public function ignoreQuietly(User $by, PulseConversation $c): void
    {
        Gate::forUser($by)->authorize('ignore', $c);

        PulseConversation::query()->whereKey($c->id)->where('status', PulseConversation::REQUESTED)
            ->update(['ignored_at' => now(), 'updated_at' => now()]);

        AuditLog::record($by, 'line.ignore', $c);
    }

    public function close(User $by, PulseConversation $c): void
    {
        Gate::forUser($by)->authorize('close', $c);

        $side = Access::side($by, $c);
        $wasRequested = $c->status === PulseConversation::REQUESTED;

        $moved = PulseConversation::query()->whereKey($c->id)
            ->whereIn('status', [PulseConversation::REQUESTED, PulseConversation::OPEN])
            ->update([
                'status' => PulseConversation::CLOSED,
                'closed_reason' => $wasRequested ? 'withdrawn' : 'closed',
                'closed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($moved === 0) {
            return;
        }

        $c->refresh();

        if (! $wasRequested) {
            $this->writeMessage($c, $by, $side, 'This line was closed.', 'system', ['event' => 'closed'], touch: false);
        }

        AuditLog::record($by, 'line.close', $c, ['was' => $wasRequested ? 'requested' : 'open']);
    }

    // ---------------------------------------------------------------- messages

    public function send(User $by, PulseConversation $c, string $body, ?int $mediaId = null): PulseMessage
    {
        Gate::forUser($by)->authorize('send', $c);
        Throttle::hit($by, 'message');

        $side = Access::side($by, $c);
        $body = $this->guard->block($body, (int) config('pulse.lines.message_max', 2000));

        if ($body === '' && $mediaId === null) {
            throw ValidationException::withMessages(['body' => 'Write something first.']);
        }

        $unlocked = $side === 'recipient' || $c->first_response_at !== null;

        if (! $unlocked) {
            $this->guard->assertPlain($body, 'body');
            if ($mediaId !== null) {
                throw ValidationException::withMessages(['body' => 'Attachments unlock once they reply.']);
            }
        }

        $moderation = $this->guard->assertNotBlocked($body, $by, 'body', ['surface' => 'message']);

        $message = DB::transaction(function () use ($by, $c, $side, $body, $mediaId, $moderation) {
            $message = $this->writeMessage($c, $by, $side, $body, 'text', $moderation['verdict'] === 'hold' ? ['flagged' => true] : null, $mediaId);

            if ($side === 'recipient' && $c->first_response_at === null) {
                PulseConversation::query()->whereKey($c->id)->whereNull('first_response_at')
                    ->update(['first_response_at' => now()]);
                $this->refillSender($c);
            }

            return $message;
        });

        $c->refresh();

        $this->notifySide($c, Access::otherSide($side), $by, [
            'type' => 'line_message',
            'title' => 'New message from '.$this->partyName($c, $side),
            'body' => $this->about($c),
            'coalesce_key' => 'line:'.$c->id,
        ], 'lines');

        return $message;
    }

    public function markRead(User $by, PulseConversation $c): void
    {
        Gate::forUser($by)->authorize('view', $c);

        $side = Access::side($by, $c);
        if ($side === null) {
            return; // staff reviewing a report do not move the read marks
        }

        // Past the last message, so a message that lands in the same second as the read still counts as new.
        $readAt = now()->startOfSecond();
        $last = PulseConversation::query()->whereKey($c->id)->value('last_message_at');
        if ($last !== null && $readAt->lte($last = Carbon::parse($last))) {
            $readAt = $last->copy()->addSecond();
        }

        PulseConversation::query()->whereKey($c->id)->update([$side.'_read_at' => $readAt]);

        DB::table('pulse_conversation_user')->updateOrInsert(
            ['pulse_conversation_id' => $c->id, 'user_id' => $by->id],
            ['last_read_at' => now(), 'side' => $side, 'entity_id' => $side === 'recipient' ? $c->entity_id : $c->initiator_entity_id],
        );
    }

    public function muteConversation(User $by, PulseConversation $c, bool $muted = true): void
    {
        Gate::forUser($by)->authorize('view', $c);

        $side = Access::side($by, $c);
        if ($side === null) {
            throw new AuthorizationException;
        }

        DB::table('pulse_conversation_user')->updateOrInsert(
            ['pulse_conversation_id' => $c->id, 'user_id' => $by->id],
            ['muted' => $muted, 'side' => $side],
        );
    }

    // ---------------------------------------------------------------- reveal

    /** The invited professional chooses to show who they are. Only they can; nobody else can do it for them. */
    public function reveal(User $by, PulseConversation $c): void
    {
        Gate::forUser($by)->authorize('reveal', $c);

        $moved = PulseConversation::query()->whereKey($c->id)->where('identity_hidden', true)
            ->update(['identity_hidden' => false, 'updated_at' => now()]);

        if ($moved === 0) {
            return;
        }

        $c->refresh();
        $c->loadMissing(['recipient']);
        $this->writeMessage($c, $by, 'recipient', 'They revealed who they are.', 'system', ['event' => 'revealed']);
        AuditLog::record($by, 'line.reveal', $c);

        $this->notifySide($c, 'initiator', $by, [
            'type' => 'line_revealed',
            'title' => $c->recipient->name.' revealed who they are',
            'body' => $this->about($c),
        ], 'lines');
    }

    // ---------------------------------------------------------------- reading

    /**
     * Filters: tab (requests|open|sent|closed|ignored|all, default open), kind, entity_id (a page id, or 'none' for
     * only the lines that are the person's own and not a page's), unread (bool), archived (bool: only the lines the
     * person archived; without it archived lines are left out of every tab), per_page.
     * Items are arrays from present(): safe to send to a client.
     *
     * @param  array<string, mixed>  $filters
     */
    public function inbox(User $u, array $filters = []): LengthAwarePaginator
    {
        $tab = (string) ($filters['tab'] ?? 'open');
        $entityIds = PulseEntityMember::query()->where('user_id', $u->id)->whereIn('role', Access::RESPONDER_ROLES)->select('entity_id');

        $recipientSide = fn ($q) => $q->where(fn ($w) => $w->where('recipient_id', $u->id)->orWhereIn('entity_id', $entityIds));
        $initiatorSide = fn ($q) => $q->where(fn ($w) => $w->where('initiator_id', $u->id)->orWhereIn('initiator_entity_id', $entityIds));

        $query = PulseConversation::query()->visibleTo($u)
            ->with(['initiator:id,name,profile_photo_path', 'entity:id,slug,name,logo_media_id', 'initiatorEntity:id,slug,name,logo_media_id']);

        match ($tab) {
            'requests' => $query->where('status', PulseConversation::REQUESTED)->whereNull('ignored_at')->where($recipientSide),
            'ignored' => $query->where('status', PulseConversation::REQUESTED)->whereNotNull('ignored_at')->where($recipientSide),
            'sent' => $query->where('status', PulseConversation::REQUESTED)->where($initiatorSide),
            'open' => $query->where('status', PulseConversation::OPEN),
            'closed' => $query->whereIn('status', [PulseConversation::CLOSED, PulseConversation::DECLINED, PulseConversation::SPAM]),
            default => null,
        };

        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        if (! empty($filters['unread'])) {
            $query->unreadFor($u, [PulseConversation::REQUESTED, PulseConversation::OPEN]);
        }

        if (($filters['entity_id'] ?? null) === 'none') {
            // Me only: a line I am on as myself, not through a page I answer for.
            $query->where(fn ($w) => $w
                ->where(fn ($a) => $a->where('recipient_id', $u->id)->whereNull('entity_id'))
                ->orWhere(fn ($a) => $a->where('initiator_id', $u->id)->whereNull('initiator_entity_id')));
        } elseif (! empty($filters['entity_id'])) {
            $query->where(fn ($w) => $w->where('entity_id', $filters['entity_id'])->orWhere('initiator_entity_id', $filters['entity_id']));
        }

        // Archive is the person's own shelf (pulse_conversation_user.archived_at), applied in the query so a page of
        // results is never shortened after it was counted.
        $archivedRows = fn ($q) => $q->select(DB::raw(1))->from('pulse_conversation_user as cu')
            ->whereColumn('cu.pulse_conversation_id', 'pulse_conversations.id')
            ->where('cu.user_id', $u->id)->whereNotNull('cu.archived_at');
        if (! empty($filters['archived'])) {
            $query->whereExists($archivedRows);
        } else {
            $query->whereNotExists($archivedRows);
        }

        // A sender never sees the lines their recipient marked as spam in the closed tab as spam: present() maps it to closed.
        $page = $query->orderByRaw('COALESCE(last_message_at, created_at) DESC')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        // The person contacted is loaded only where the line is not anonymous: for an anonymous invite the row is never read.
        $page->getCollection()->filter(fn (PulseConversation $c) => ! $c->identity_hidden)->load('recipient:id,name,profile_photo_path');

        $snippets = $this->snippets($page->getCollection()->pluck('id'));

        $page->getCollection()->transform(function (PulseConversation $c) use ($u, $snippets) {
            return $this->presenter->present($u, $c, $snippets[$c->id] ?? null);
        });

        return $page;
    }

    /** Put a line on the person's own shelf: it leaves every tab for them and nobody else is told. */
    public function archive(User $by, PulseConversation $c): void
    {
        $this->setArchived($by, $c, true);
    }

    public function unarchive(User $by, PulseConversation $c): void
    {
        $this->setArchived($by, $c, false);
    }

    private function setArchived(User $by, PulseConversation $c, bool $archived): void
    {
        Gate::forUser($by)->authorize('view', $c);

        $side = Access::side($by, $c);
        if ($side === null) {
            throw new AuthorizationException; // staff reviewing a report have no shelf
        }

        DB::table('pulse_conversation_user')->updateOrInsert(
            ['pulse_conversation_id' => $c->id, 'user_id' => $by->id],
            ['archived_at' => $archived ? now() : null, 'side' => $side, 'entity_id' => $side === 'recipient' ? $c->entity_id : $c->initiator_entity_id],
        );
    }

    /** @return array<string, mixed> */
    public function present(User $viewer, PulseConversation $c): array
    {
        Gate::forUser($viewer)->authorize('view', $c);
        $this->loadParties($c);

        return $this->presenter->present($viewer, $c);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function thread(User $viewer, PulseConversation $c, ?int $afterId = null): array
    {
        Gate::forUser($viewer)->authorize('view', $c);
        $this->loadParties($c);

        return $this->presenter->thread($viewer, $c, $afterId);
    }

    // ---------------------------------------------------------------- helpers shared with the other services

    public function addParticipants(PulseConversation $c): void
    {
        $rows = [];

        foreach (['initiator', 'recipient'] as $side) {
            foreach (Access::usersFor($c, $side) as $user) {
                $rows[$user->id] = [
                    'pulse_conversation_id' => $c->id,
                    'user_id' => $user->id,
                    'side' => $side,
                    'entity_id' => $side === 'initiator' ? $c->initiator_entity_id : $c->entity_id,
                    'muted' => false,
                    'last_read_at' => $side === 'initiator' && $user->id === $c->initiator_id ? now() : null,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('pulse_conversation_user')->insertOrIgnore(array_values($rows));
        }
    }

    public function writeMessage(PulseConversation $c, User $author, ?string $side, string $body, string $kind = 'text', ?array $meta = null, ?int $mediaId = null, bool $touch = true): PulseMessage
    {
        $message = PulseMessage::create([
            'pulse_conversation_id' => $c->id,
            'user_id' => $author->id,
            'body' => $body,
            'kind' => $kind,
            'meta' => $meta,
            'media_id' => $mediaId,
            'side' => $side,
        ]);

        if ($touch && $side !== null) {
            // Read marks are second precise and markRead sets one just past the last message. A reply that lands in
            // that same second would look older than the mark and be counted as read, so the message time is never
            // earlier than the other side's mark: equal means new (see LinePresenter::unread).
            $otherMark = PulseConversation::query()->whereKey($c->id)->value(Access::otherSide($side).'_read_at');
            $at = $otherMark !== null && ($mark = Carbon::parse($otherMark))->gt(now()) ? $mark : now();

            $stamp = ['last_message_at' => $at, 'last_message_side' => $side, $side.'_read_at' => $at, 'updated_at' => now()];
            PulseConversation::query()->whereKey($c->id)->update($stamp);
        }

        return $message;
    }

    /**
     * Notify every user on one side of a line, except the actor and anyone who muted the line.
     *
     * @param  array<string, mixed>  $payload
     */
    public function notifySide(PulseConversation $c, string $side, ?User $except, array $payload, string $category = 'lines'): void
    {
        $muted = DB::table('pulse_conversation_user')->where('pulse_conversation_id', $c->id)->where('muted', true)->pluck('user_id')->all();
        $payload += [
            'conversation_id' => $c->id,
            'route' => ['name' => 'lines.show', 'params' => ['conversation' => $c->id]],
            'anonymous' => $c->identity_hidden && $side === 'initiator',
        ];

        foreach (Access::usersFor($c, $side) as $user) {
            if ($user->id === $except?->id || in_array($user->id, $muted, true)) {
                continue;
            }

            $this->notifier->notify($user, $category, $payload);
        }
    }

    /** The name of one side as the other side may know it: a page, a person, or "An anonymous professional". */
    public function partyName(PulseConversation $c, string $side): string
    {
        $this->loadParties($c);
        $party = $this->presenter->counterpart($c, Access::otherSide($side));

        return $party['kind'] === 'anonymous' ? 'An anonymous professional' : $party['name'];
    }

    // ---------------------------------------------------------------- private

    /** Load what a screen needs about the parties. The invited professional of an anonymous line is never loaded. */
    private function loadParties(PulseConversation $c): void
    {
        $c->loadMissing(['initiator', 'entity', 'initiatorEntity']);

        if (! $c->identity_hidden) {
            $c->loadMissing('recipient');
        }
    }

    /**
     * @return array{user: ?User, entity: ?PulseEntity, as_entity: ?PulseEntity, signal: ?PulseSignal, door: ?PulseDoor, context: ?Model, hidden: bool, gate: Model}
     */
    private function resolveTarget(User $from, array $target, string $kind): array
    {
        $out = ['user' => null, 'entity' => null, 'as_entity' => null, 'signal' => null, 'door' => null, 'context' => null, 'hidden' => false];

        if (isset($target['as_entity'])) {
            $page = PulseEntity::query()->find($target['as_entity']);
            if (! $page || ! Access::canRespondFor($from, $page)) {
                throw new AuthorizationException('You do not answer for that page.');
            }
            $out['as_entity'] = $page;
        }

        if (isset($target['signal'])) {
            $signal = PulseSignal::query()->with('place')->find($target['signal']);
            if (! $signal || $signal->status !== 'live' || ($signal->owner_id === null && $signal->entity_id === null)) {
                throw LineException::because('unavailable', 'They cannot be contacted right now.');
            }
            if ($signal->kind === 'talent' && $kind !== 'invite') {
                throw LineException::because('not_allowed', 'Professionals are invited by employer pages. Send an invitation from your page.');
            }
            $out['signal'] = $signal;
            $out['context'] = $signal;
            $out['user'] = $signal->owner_id ? User::query()->find($signal->owner_id) : null;
            $out['entity'] = $out['user'] ? null : $signal->entity;
            $out['hidden'] = $signal->kind === 'talent' && $signal->anonymous;
        } elseif (isset($target['door'])) {
            $door = PulseDoor::query()->find($target['door']);
            if (! $door || ! $door->isOpen()) {
                throw LineException::because('unavailable', 'That door has closed.');
            }
            $out['door'] = $door;
            $out['context'] = $door;
            $out['user'] = $door->owner_id ? User::query()->find($door->owner_id) : null;
            $out['entity'] = $door->entity_id ? PulseEntity::query()->find($door->entity_id) : null;
        } elseif (isset($target['user'])) {
            $out['user'] = User::query()->find($target['user']);
        } elseif (isset($target['entity'])) {
            $out['entity'] = PulseEntity::query()->find($target['entity']);
        }

        if ($out['user'] === null && $out['entity'] === null) {
            throw LineException::because('unavailable', 'They cannot be contacted right now.');
        }

        if ($kind === 'invite') {
            $this->prepareInvite($out);
        } elseif ($out['as_entity'] !== null) {
            throw LineException::because('invalid', 'Only an invitation can be sent as a page.');
        }

        $out['gate'] = $out['signal'] ?? $out['door'] ?? $out['user'] ?? $out['entity'];

        return $out;
    }

    /** An invitation is sent by an employer page to a professional who can be found. */
    private function prepareInvite(array &$t): void
    {
        $page = $t['as_entity'];

        if ($page === null || ! $page->is_employer || $page->status !== 'live') {
            throw LineException::because('not_allowed', 'Invitations are sent by a live employer page.');
        }

        if ($t['user'] === null) {
            throw LineException::because('invalid', 'You can invite a professional, not a page.');
        }

        $discoverability = PulseProfile::query()->where('user_id', $t['user']->id)->value('discoverability');
        if (! in_array($discoverability, ['anonymous', 'open'], true)) {
            throw LineException::because('unavailable', 'They cannot be contacted right now.');
        }

        if ($t['signal'] === null) {
            $t['signal'] = PulseSignal::query()->with('place')->where('owner_id', $t['user']->id)->where('kind', 'talent')->where('status', 'live')->first();
            if ($t['signal'] === null) {
                throw LineException::because('unavailable', 'They cannot be contacted right now.');
            }
            $t['context'] = $t['signal'];
        }

        // Whoever the signal is, the profile's own setting wins: anonymous stays anonymous until they reveal.
        $t['hidden'] = $discoverability === 'anonymous' || ($t['signal']->kind === 'talent' && $t['signal']->anonymous);
        $t['entity'] = null;
    }

    /** @return array<string, string> */
    public function cleanCard(User $from, array $card): array
    {
        $unknown = array_diff(array_keys($card), array_keys(self::CARD_FIELDS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['card' => 'An enquiry card has four fields: what, when or how many, where, and a budget band.']);
        }

        $out = [];
        foreach (self::CARD_FIELDS as $field => $max) {
            $value = $this->guard->line($card[$field] ?? '', 1000);

            if (mb_strlen($value) > $max) {
                throw ValidationException::withMessages(["card.{$field}" => "Keep this under {$max} characters."]);
            }

            if ($value !== '' && $this->guard->violations($value) !== []) {
                throw ValidationException::withMessages(["card.{$field}" => TextGuard::UNLOCK_MESSAGE]);
            }

            if ($value !== '') {
                $out[$field] = $value;
            }
        }

        if (mb_strlen($out['what'] ?? '') < 3) {
            throw ValidationException::withMessages(['card.what' => 'Say in a few words what this is about.']);
        }

        $this->guard->assertNotBlocked(implode("\n", $out), $from, 'card.what', ['surface' => 'knock']);

        return $out;
    }

    public function composeCard(array $card): string
    {
        $labels = ['what' => 'What', 'when_or_qty' => 'When or how many', 'where' => 'Where', 'budget_band' => 'Budget'];

        return collect($card)->map(fn ($v, $k) => ($labels[$k] ?? $k).': '.$v)->implode("\n");
    }

    private function findActive(User $from, array $t, string $kind, ?Model $context): ?PulseConversation
    {
        return PulseConversation::query()
            ->where('initiator_id', $from->id)->where('kind', $kind)
            ->whereIn('status', [PulseConversation::REQUESTED, PulseConversation::OPEN])
            ->when($t['entity'], fn ($q) => $q->where('entity_id', $t['entity']->id), fn ($q) => $q->where('recipient_id', $t['user']->id))
            ->when($context, fn ($q) => $q->where('context_type', Context::describe($context)['type'])->where('context_id', $context->getKey()))
            ->when($t['door'], fn ($q) => $q->where('door_id', $t['door']->id))
            ->when($t['as_entity'], fn ($q) => $q->where('initiator_entity_id', $t['as_entity']->id))
            ->first();
    }

    private function assertNotRecentlyDeclined(User $from, array $t, ?Model $context): void
    {
        $days = (int) config('pulse.lines.redeclined_cooldown_days', 30);

        $recent = PulseConversation::query()
            ->where('initiator_id', $from->id)->where('status', PulseConversation::DECLINED)
            ->where('closed_at', '>', now()->subDays($days))
            ->when($t['entity'], fn ($q) => $q->where('entity_id', $t['entity']->id), fn ($q) => $q->where('recipient_id', $t['user']->id))
            ->when($context, fn ($q) => $q->where('context_type', Context::describe($context)['type'])->where('context_id', $context->getKey()))
            ->exists();

        if ($recent) {
            throw LineException::because('exists', 'You asked about this recently and the answer was not now. Try again in a few weeks.');
        }
    }

    /** One knock per person per door, and the door holds a limited number. */
    private function takeDoorSlot(User $from, PulseDoor $door): void
    {
        if (PulseConversation::query()->where('door_id', $door->id)->where('initiator_id', $from->id)->exists()) {
            throw LineException::because('exists', 'You have already knocked on this door.');
        }

        $taken = PulseDoor::query()->whereKey($door->id)->where('status', PulseDoor::OPEN)
            ->whereColumn('knocks_count', '<', 'knock_cap')
            ->update(['knocks_count' => DB::raw('knocks_count + 1'), 'updated_at' => now()]);

        if ($taken === 0) {
            throw LineException::because('limit', 'This door is full for now.');
        }
    }

    /** The first reply from a stranger gives the sender one first contact back. Once per line. */
    private function refillSender(PulseConversation $c): void
    {
        if ($c->initiator_id === null) {
            return;
        }

        $first = PulseConversation::query()->whereKey($c->id)->whereNull('refilled_at')->update(['refilled_at' => now()]);

        if ($first) {
            $sender = User::query()->find($c->initiator_id);
            if ($sender) {
                $this->budget->refill($sender);
            }
        }
    }

    private function notifyRequest(PulseConversation $c, User $from): void
    {
        $about = $this->about($c);

        if ($c->identity_hidden || $c->initiator_entity_id !== null) {
            $title = ($c->initiatorEntity?->name ?? 'A page').' invited you to talk';
        } elseif ($c->status === PulseConversation::OPEN) {
            $title = 'New message from '.$from->name;
        } else {
            $title = match ($c->kind) {
                'application' => $from->name.' applied',
                'enquiry' => $from->name.' sent an enquiry',
                default => $from->name.' sent a request',
            };
        }

        $this->notifySide($c, 'recipient', $from, [
            'type' => 'line_request',
            'title' => $title,
            'body' => $about,
        ], 'lines');
    }

    private function about(PulseConversation $c): string
    {
        return $c->context_title ? 'About: '.$c->context_title : (string) ($c->card['what'] ?? '');
    }

    /** @return array<int, string> */
    private function snippets(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $latest = PulseMessage::query()
            ->whereIn('id', PulseMessage::query()->selectRaw('MAX(id)')->whereIn('pulse_conversation_id', $ids)->groupBy('pulse_conversation_id'))
            ->get(['id', 'pulse_conversation_id', 'body', 'kind', 'erased_at']);

        return $latest->mapWithKeys(fn (PulseMessage $m) => [
            $m->pulse_conversation_id => $m->erased_at ? 'This message was deleted.' : mb_substr(str_replace("\n", ' ', $m->body), 0, 120),
        ])->all();
    }
}

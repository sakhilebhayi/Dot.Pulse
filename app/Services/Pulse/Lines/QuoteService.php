<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseQuote;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quote slips (D20). A person asks up to three businesses at once, on one card. The businesses pay nothing to see
 * the request and reply on a standard slip: a price or a range, the earliest date, what is included and what is
 * not, and until when the slip holds. The person accepts one (the others are told they were not chosen), declines
 * or lets them expire; every request ends answered, declined or expired.
 */
class QuoteService
{
    public const MAX_PROVIDERS = 3;

    public function __construct(
        private readonly LineService $lines,
        private readonly ContactGate $gate,
        private readonly KnockBudget $budget,
        private readonly TextGuard $guard,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array{need?: int, offer?: int}  $needOrOffer  the sender's own live need, or an offer that accepts quotes
     * @param  list<int>  $providerEntityIds  at most three businesses
     * @param  array{what?: string, when_or_qty?: string, where?: string, budget_band?: string}  $details
     * @return list<PulseConversation> one line per provider, sharing a quote group
     */
    public function request(User $from, array $needOrOffer, array $providerEntityIds, array $details): array
    {
        Throttle::hit($from, 'knock');

        $ids = array_values(array_unique(array_map('intval', $providerEntityIds)));

        [$context, $offer] = $this->resolveSubject($from, $needOrOffer);

        if ($offer !== null && ! in_array($offer->entity_id, $ids, true)) {
            array_unshift($ids, $offer->entity_id);
        }

        if ($ids === [] || count($ids) > self::MAX_PROVIDERS) {
            throw LineException::because('limit', 'Ask between one and three businesses. More than three turns a request into a race.');
        }

        $card = $this->lines->cleanCard($from, $details);
        $entities = PulseEntity::query()->whereIn('id', $ids)->get()->keyBy('id');

        if ($entities->count() !== count($ids)) {
            throw LineException::because('unavailable', 'One of those businesses cannot be asked right now.');
        }

        $stranger = false;
        foreach ($ids as $id) {
            $entity = $entities[$id];
            if (! $entity->is_seller) {
                throw LineException::because('not_allowed', $entity->name.' does not sell anything, so it cannot quote.');
            }

            $decision = $this->gate->can($from, $entity, $this->gateContext($context, $entity));
            if (! $decision['allowed']) {
                throw LineException::because($decision['code'] === 'budget' ? 'budget' : 'not_allowed', $entity->name.': '.$decision['why']);
            }
            $stranger = $stranger || $decision['stranger'];
        }

        $described = Context::describe($context);

        // The cap of three is per thing asked about, not per request: a second request for the same need or offer cannot
        // widen the circle, and a business that already has a live request for it is not asked again.
        $asked = PulseConversation::query()
            ->where('initiator_id', $from->id)->where('kind', 'quote')
            ->where('context_type', $described['type'])->where('context_id', $described['id'])
            ->whereIn('status', [PulseConversation::REQUESTED, PulseConversation::OPEN])
            ->pluck('entity_id')->map(fn ($id) => (int) $id)->unique();

        if ($asked->intersect($ids)->isNotEmpty()) {
            throw LineException::because('exists', 'You already asked one of those businesses about this. Wait for their answer or close that request first.');
        }

        if ($asked->merge($ids)->unique()->count() > self::MAX_PROVIDERS) {
            throw LineException::because('limit', 'Three is the most for one request, counting the businesses you already asked about this. Close one before you ask another.');
        }

        $group = (string) Str::uuid();

        $conversations = DB::transaction(function () use ($from, $ids, $card, $described, $group, $stranger) {
            if ($stranger) {
                $this->budget->spend($from); // one knock for the whole request, not one per business
            }

            $made = [];
            foreach ($ids as $id) {
                $c = PulseConversation::create([
                    'type' => 'direct',
                    'kind' => 'quote',
                    'status' => PulseConversation::REQUESTED,
                    'initiator_id' => $from->id,
                    'entity_id' => $id,
                    'quote_group' => $group,
                    'context_type' => $described['type'],
                    'context_id' => $described['id'],
                    'context_title' => mb_substr($described['title'], 0, 160),
                    'context_snapshot' => $described['snapshot'],
                    'card' => $card,
                    'last_message_at' => now(),
                    'last_message_side' => 'initiator',
                    'initiator_read_at' => now(),
                ]);

                $this->lines->addParticipants($c);
                $this->lines->writeMessage($c, $from, 'initiator', $this->lines->composeCard($card), 'quote_request', ['card' => $card]);
                AuditLog::record($from, 'quote.request', $c, ['group' => $group, 'providers' => count($ids)]);
                $made[] = $c;
            }

            return $made;
        });

        foreach ($conversations as $c) {
            $c->loadMissing(['initiator', 'entity']);
            $this->lines->notifySide($c, 'recipient', $from, [
                'type' => 'quote_requested',
                'title' => $from->name.' asked for a quote',
                'body' => 'About: '.$c->context_title,
            ], 'lines');
        }

        return $conversations;
    }

    /**
     * The provider replies on a slip. Replying also answers the request (the line opens).
     *
     * @param  array{amount_cents: int, amount_max_cents?: ?int, currency?: string, unit?: ?string, earliest_date: string, included: string, excluded?: ?string, valid_until: string, notes?: ?string, offer_id?: ?int}  $slip
     */
    public function respond(User $by, PulseConversation $c, array $slip): PulseQuote
    {
        Gate::forUser($by)->authorize('respond', $c);

        $data = Validator::make($slip, [
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'amount_max_cents' => ['nullable', 'integer', 'gt:amount_cents', 'max:100000000000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'unit' => ['nullable', 'string', 'max:24'],
            'earliest_date' => ['required', 'date', 'after_or_equal:today'],
            'included' => ['required', 'string', 'max:600'],
            'excluded' => ['nullable', 'string', 'max:600'],
            'valid_until' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addDays((int) config('pulse.lines.quote_max_validity_days', 90))->toDateString()],
            'notes' => ['nullable', 'string', 'max:600'],
            'offer_id' => ['nullable', 'integer'],
        ], [
            'amount_max_cents.gt' => 'The top of the range must be more than the low end.',
            'earliest_date.after_or_equal' => 'The earliest date cannot be in the past.',
            'valid_until.after_or_equal' => 'A slip must hold until today or later.',
            'valid_until.before_or_equal' => 'A slip can hold for at most '.(int) config('pulse.lines.quote_max_validity_days', 90).' days.',
        ])->validate();

        foreach (['included', 'excluded', 'notes'] as $field) {
            if (! empty($data[$field]) && $this->guard->violations($data[$field]) !== []) {
                throw ValidationException::withMessages([$field => TextGuard::UNLOCK_MESSAGE]);
            }
        }

        $offerId = null;
        if (! empty($data['offer_id'])) {
            $offerId = PulseOffer::query()->where('entity_id', $c->entity_id)->whereKey($data['offer_id'])->value('id');
        }

        $first = $c->status === PulseConversation::REQUESTED;

        $quote = DB::transaction(function () use ($c, $data, $offerId, $first, $by) {
            PulseQuote::query()->where('pulse_conversation_id', $c->id)->where('status', PulseQuote::SENT)
                ->update(['status' => PulseQuote::SUPERSEDED, 'updated_at' => now()]);

            $quote = PulseQuote::create([
                'pulse_conversation_id' => $c->id,
                'entity_id' => $c->entity_id,
                'offer_id' => $offerId,
                'amount_cents' => $data['amount_cents'],
                'amount_max_cents' => $data['amount_max_cents'] ?? null,
                'currency' => strtoupper($data['currency'] ?? 'ZAR'),
                'unit' => $data['unit'] ?? null,
                'notes' => $data['notes'] ?? null,
                'earliest_date' => $data['earliest_date'],
                'included' => $data['included'],
                'excluded' => $data['excluded'] ?? null,
                'valid_until' => $data['valid_until'],
                'status' => PulseQuote::SENT,
            ]);

            if ($first) {
                PulseConversation::query()->whereKey($c->id)->where('status', PulseConversation::REQUESTED)->update([
                    'status' => PulseConversation::OPEN,
                    'first_response_at' => now(),
                    'recipient_read_at' => now(),
                    'ignored_at' => null,
                ]);
            }

            $message = $this->lines->writeMessage($c, $by, 'recipient', $this->summary($quote), 'quote', ['quote_id' => $quote->id]);
            $quote->forceFill(['message_id' => $message->id])->save();
            AuditLog::record($by, 'quote.respond', $quote, ['conversation' => $c->id]);

            return $quote;
        });

        $c->refresh();
        if ($first) {
            $this->refill($c);
        }

        $this->lines->notifySide($c, 'initiator', $by, [
            'type' => 'quote_received',
            'title' => ($c->entity?->name ?? 'A business').' sent you a quote',
            'body' => $this->summary($quote),
            'coalesce_key' => 'quote:'.$c->id,
        ], 'lines');

        return $quote;
    }

    /** The person who asked takes this quote. The other providers in the group hear they were not chosen. */
    public function accept(User $by, PulseQuote $q): void
    {
        Gate::forUser($by)->authorize('accept', $q);

        $moved = PulseQuote::query()->whereKey($q->id)->where('status', PulseQuote::SENT)
            ->whereDate('valid_until', '>=', now()->toDateString())
            ->update(['status' => PulseQuote::ACCEPTED, 'responded_at' => now(), 'updated_at' => now()]);

        if ($moved === 0) {
            throw LineException::because('not_open', 'That quote has expired or was already answered.');
        }

        $q->refresh();
        $c = $q->conversation;
        $this->lines->writeMessage($c, $by, 'initiator', 'Quote accepted.', 'system', ['event' => 'quote_accepted', 'quote_id' => $q->id]);
        AuditLog::record($by, 'quote.accept', $q);

        $this->lines->notifySide($c, 'recipient', $by, [
            'type' => 'quote_accepted',
            'title' => $by->name.' accepted your quote',
            'body' => 'About: '.$c->context_title,
        ], 'lines');

        $this->closeSiblings($by, $c);
    }

    public function decline(User $by, PulseQuote $q): void
    {
        Gate::forUser($by)->authorize('decline', $q);

        $moved = PulseQuote::query()->whereKey($q->id)->where('status', PulseQuote::SENT)
            ->update(['status' => PulseQuote::DECLINED, 'responded_at' => now(), 'updated_at' => now()]);

        if ($moved === 0) {
            throw LineException::because('not_open', 'That quote was already answered.');
        }

        $q->refresh();
        $c = $q->conversation;
        $this->lines->writeMessage($c, $by, 'initiator', 'Quote declined.', 'system', ['event' => 'quote_declined', 'quote_id' => $q->id]);
        AuditLog::record($by, 'quote.decline', $q);

        $this->lines->notifySide($c, 'recipient', $by, [
            'type' => 'quote_declined',
            'title' => 'Your quote was declined',
            'body' => 'About: '.$c->context_title,
        ], 'lines');
    }

    /**
     * Quotes past their valid_until expire, and both sides are told. Returns how many expired.
     */
    public function expireStale(): int
    {
        $stale = PulseQuote::query()->live()->whereDate('valid_until', '<', now()->toDateString())->with('conversation.entity')->get();

        foreach ($stale as $quote) {
            $moved = PulseQuote::query()->whereKey($quote->id)->where('status', PulseQuote::SENT)
                ->update(['status' => PulseQuote::EXPIRED, 'responded_at' => now(), 'updated_at' => now()]);

            if (! $moved) {
                continue;
            }

            $c = $quote->conversation;
            $entityName = $c->entity?->name ?? 'A business';

            $this->lines->notifySide($c, 'initiator', null, [
                'type' => 'quote_expired',
                'title' => 'The quote from '.$entityName.' has expired',
                'body' => 'About: '.$c->context_title.'. Ask them to quote again if you still need it.',
            ], 'lines');
            $this->lines->notifySide($c, 'recipient', null, [
                'type' => 'quote_expired',
                'title' => 'Your quote for '.$c->context_title.' expired without an answer',
                'body' => '',
            ], 'lines');
        }

        return $stale->count();
    }

    /** @return array{0: Model, 1: ?PulseOffer} */
    private function resolveSubject(User $from, array $needOrOffer): array
    {
        if (isset($needOrOffer['need'])) {
            $need = PulseNeed::query()->where('user_id', $from->id)->find($needOrOffer['need']);
            if (! $need || ! Context::isLive($need)) {
                throw LineException::because('unavailable', 'Quote requests start from one of your own live needs.');
            }

            return [$need, null];
        }

        if (isset($needOrOffer['offer'])) {
            $offer = PulseOffer::query()->find($needOrOffer['offer']);
            if (! $offer || ! Context::isLive($offer) || ! $offer->accepts_quotes) {
                throw LineException::because('unavailable', 'That offer is not taking quote requests right now.');
            }

            return [$offer, $offer];
        }

        throw LineException::because('invalid', 'Say which need or offer the quote is for.');
    }

    /**
     * The context a provider's contact policy is checked against. A need is a context for any seller that quotes.
     * An offer is a context for its own page; another page is asked on the strength of its own quoting offer.
     */
    private function gateContext(Model $context, PulseEntity $entity): ?Model
    {
        if ($context instanceof PulseOffer && $context->entity_id !== $entity->id) {
            return PulseOffer::query()->where('entity_id', $entity->id)->where('status', 'live')
                ->where('moderation', 'approved')->where('accepts_quotes', true)->first();
        }

        return $context;
    }

    private function closeSiblings(User $by, PulseConversation $chosen): void
    {
        if ($chosen->quote_group === null) {
            return;
        }

        $others = PulseConversation::query()
            ->where('quote_group', $chosen->quote_group)->whereKeyNot($chosen->id)
            ->whereIn('status', [PulseConversation::REQUESTED, PulseConversation::OPEN])->get();

        foreach ($others as $other) {
            PulseConversation::query()->whereKey($other->id)->update([
                'status' => PulseConversation::CLOSED,
                'closed_reason' => 'not_chosen',
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
            PulseQuote::query()->where('pulse_conversation_id', $other->id)->where('status', PulseQuote::SENT)
                ->update(['status' => PulseQuote::DECLINED, 'responded_at' => now(), 'updated_at' => now()]);

            $this->lines->notifySide($other, 'recipient', null, [
                'type' => 'quote_not_chosen',
                'title' => 'They went with another business for '.$other->context_title,
                'body' => 'This request is closed. Thank you for replying.',
            ], 'lines');
        }
    }

    private function refill(PulseConversation $c): void
    {
        $first = PulseConversation::query()->whereKey($c->id)->whereNull('refilled_at')->update(['refilled_at' => now()]);

        if ($first && $c->initiator_id && ($sender = User::query()->find($c->initiator_id))) {
            $this->budget->refill($sender);
        }
    }

    private function summary(PulseQuote $q): string
    {
        $price = $q->isRange()
            ? Money::text($q->amount_cents, $q->currency).' to '.Money::text($q->amount_max_cents, $q->currency)
            : Money::text($q->amount_cents, $q->currency);

        return trim("{$price}".($q->unit ? " {$q->unit}" : '')
            .'. Earliest '.$q->earliest_date->format('j M Y')
            .'. Valid until '.$q->valid_until->format('j M Y').'.');
    }
}

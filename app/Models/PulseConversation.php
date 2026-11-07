<?php

namespace App\Models;

use App\Services\Pulse\Lines\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A line: one conversation that started from something (an offer, a role, a need, a door) and ends answered,
 * declined, closed or expired. Do not serialise this model straight to a client: read it through
 * App\Services\Pulse\Lines\LineService::present()/thread(), which hide the identity of an anonymous professional.
 */
class PulseConversation extends Model
{
    public const KINDS = ['dm', 'enquiry', 'quote', 'application', 'intro', 'collab', 'invite'];

    public const REQUESTED = 'requested';

    public const OPEN = 'open';

    public const DECLINED = 'declined';

    public const CLOSED = 'closed';

    public const SPAM = 'spam';

    public const OUTCOMES = ['none', 'won', 'lost', 'hired', 'partnered'];

    /** Outcomes that count as a result (a confirmed 'lost' only closes the loop). */
    public const POSITIVE_OUTCOMES = ['won', 'hired', 'partnered'];

    protected $table = 'pulse_conversations';

    protected $fillable = [
        'type', 'name', 'kind', 'status', 'initiator_id', 'recipient_id', 'entity_id', 'initiator_entity_id', 'door_id',
        'quote_group', 'context_type', 'context_id', 'context_title', 'context_snapshot', 'card', 'identity_hidden',
        'outcome', 'outcome_side', 'outcome_initiator_at', 'outcome_recipient_at', 'outcome_disputed_at',
        'first_response_at', 'last_message_at', 'last_message_side', 'closed_at', 'closed_reason', 'decline_reason',
        'ignored_at', 'refilled_at', 'initiator_read_at', 'recipient_read_at',
    ];

    /** The person contacted is not exposed by serialisation: for an anonymous invite the page must never learn it. */
    protected $hidden = ['recipient_id'];

    protected function casts(): array
    {
        return [
            'identity_hidden' => 'boolean',
            'card' => 'array',
            'context_snapshot' => 'array',
            'outcome_initiator_at' => 'datetime',
            'outcome_recipient_at' => 'datetime',
            'outcome_disputed_at' => 'datetime',
            'first_response_at' => 'datetime',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
            'ignored_at' => 'datetime',
            'refilled_at' => 'datetime',
            'initiator_read_at' => 'datetime',
            'recipient_read_at' => 'datetime',
        ];
    }

    /** An anonymous line also drops the pointer to the signal it came from: the signal row names its owner. */
    public function toArray(): array
    {
        $array = parent::toArray();

        if ($this->identity_hidden) {
            unset($array['context_id']);
        }

        return $array;
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** The page that is being contacted. */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    /** The page that started the line (a talent invite). */
    public function initiatorEntity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'initiator_entity_id');
    }

    public function door(): BelongsTo
    {
        return $this->belongsTo(PulseDoor::class, 'door_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PulseMessage::class, 'pulse_conversation_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(PulseQuote::class, 'pulse_conversation_id');
    }

    /** Per person state (read mark, muted, archived). Access is decided by Access, not by this table. */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pulse_conversation_user', 'pulse_conversation_id', 'user_id')
            ->withPivot(['last_read_at', 'side', 'entity_id', 'muted', 'archived_at']);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isRequested(): bool
    {
        return $this->status === self::REQUESTED;
    }

    /** Both sides agreed on the same, real outcome. */
    public function hasConfirmedOutcome(): bool
    {
        return $this->outcome !== 'none' && $this->outcome_initiator_at !== null && $this->outcome_recipient_at !== null;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    public function scopeRequested(Builder $query): Builder
    {
        return $query->where('status', self::REQUESTED);
    }

    /** Lines where both sides confirmed the same outcome. What metrics, reviews and verification count. */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('outcome', '!=', 'none')
            ->whereNotNull('outcome_initiator_at')
            ->whereNotNull('outcome_recipient_at');
    }

    /** Confirmed outcomes that are a result: a sale, a hire, a partnership. */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->confirmed()->whereIn('outcome', self::POSITIVE_OUTCOMES);
    }

    /** Lines a user may see: their own, plus the lines of every page they answer for. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $entityIds = PulseEntityMember::query()
            ->where('user_id', $user->id)
            ->whereIn('role', Access::RESPONDER_ROLES)
            ->select('entity_id');

        return $query->where(function (Builder $q) use ($user, $entityIds) {
            $q->where('initiator_id', $user->id)
                ->orWhere('recipient_id', $user->id)
                ->orWhereIn('entity_id', $entityIds)
                ->orWhereIn('initiator_entity_id', $entityIds);
        });
    }

    /** Responder entity ids for a user, as a subquery. */
    private static function responderEntityIds(User $user)
    {
        return PulseEntityMember::query()
            ->where('user_id', $user->id)
            ->whereIn('role', Access::RESPONDER_ROLES)
            ->select('entity_id');
    }

    /**
     * Lines (in the given statuses) with something new for this user: the last message came from the other side
     * and is newer than the user's side's read mark. Requests the user let lapse quietly are not counted.
     *
     * @param  list<string>  $statuses
     */
    public function scopeUnreadFor(Builder $query, User $user, array $statuses = [self::REQUESTED, self::OPEN]): Builder
    {
        $entityIds = self::responderEntityIds($user);

        return $query->whereIn('status', $statuses)->where(function (Builder $q) use ($user, $entityIds) {
            $q->where(function (Builder $a) use ($user, $entityIds) {
                $a->where(fn (Builder $w) => $w->where('recipient_id', $user->id)->orWhereIn('entity_id', $entityIds))
                    ->whereNull('ignored_at')
                    ->where('last_message_side', 'initiator')
                    ->where(fn (Builder $r) => $r->whereNull('recipient_read_at')->orWhereColumn('last_message_at', '>=', 'recipient_read_at'));
            })->orWhere(function (Builder $a) use ($user, $entityIds) {
                $a->where(fn (Builder $w) => $w->where('initiator_id', $user->id)->orWhereIn('initiator_entity_id', $entityIds))
                    ->where('last_message_side', 'recipient')
                    ->where(fn (Builder $r) => $r->whereNull('initiator_read_at')->orWhereColumn('last_message_at', '>=', 'initiator_read_at'));
            });
        });
    }

    /** Requests waiting for this user's answer (not the ones they let lapse). */
    public function scopeAwaitingAnswerFrom(Builder $query, User $user): Builder
    {
        $entityIds = self::responderEntityIds($user);

        return $query->where('status', self::REQUESTED)->whereNull('ignored_at')
            ->where(fn (Builder $w) => $w->where('recipient_id', $user->id)->orWhereIn('entity_id', $entityIds));
    }
}

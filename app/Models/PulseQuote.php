<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A quote slip: a price or range, the earliest date, what is included and what is not, and until when it holds. */
class PulseQuote extends Model
{
    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const SUPERSEDED = 'superseded';

    protected $table = 'pulse_quotes';

    protected $fillable = [
        'pulse_conversation_id',
        'message_id',
        'entity_id',
        'offer_id',
        'amount_cents',
        'amount_max_cents',
        'currency',
        'unit',
        'notes',
        'earliest_date',
        'included',
        'excluded',
        'valid_until',
        'status',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'earliest_date' => 'date',
            'responded_at' => 'datetime',
            'amount_cents' => 'integer',
            'amount_max_cents' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PulseConversation::class, 'pulse_conversation_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(PulseOffer::class, 'offer_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(PulseMessage::class, 'message_id');
    }

    public function isRange(): bool
    {
        return $this->amount_max_cents !== null && $this->amount_max_cents > $this->amount_cents;
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::SENT);
    }
}

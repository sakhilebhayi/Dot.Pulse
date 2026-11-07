<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseQuote extends Model
{
    protected $table = 'pulse_quotes';

    protected $fillable = [
        'pulse_conversation_id',
        'message_id',
        'entity_id',
        'offer_id',
        'amount_cents',
        'currency',
        'unit',
        'notes',
        'valid_until',
        'status',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'responded_at' => 'datetime',
            'amount_cents' => 'integer',
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
}

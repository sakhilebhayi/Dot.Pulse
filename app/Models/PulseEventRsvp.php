<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseEventRsvp extends Model
{
    protected $table = 'pulse_event_rsvps';

    protected $fillable = ['pulse_event_id', 'user_id', 'status'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(PulseEvent::class, 'pulse_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

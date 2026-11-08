<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulsePollVote extends Model
{
    protected $table = 'pulse_poll_votes';

    protected $fillable = ['pulse_poll_id', 'user_id', 'option_index'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(PulsePoll::class, 'pulse_poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

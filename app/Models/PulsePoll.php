<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PulsePoll extends Model
{
    protected $table = 'pulse_polls';

    protected $fillable = ['pulse_post_id', 'options', 'closes_at', 'votes_count'];

    protected $casts = ['options' => 'array', 'closes_at' => 'datetime'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(PulsePost::class, 'pulse_post_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PulsePollVote::class);
    }
}

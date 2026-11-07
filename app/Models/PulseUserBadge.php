<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseUserBadge extends Model
{
    protected $table = 'pulse_user_badges';

    protected $fillable = ['user_id', 'pulse_badge_id', 'awarded_at'];

    protected $casts = ['awarded_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(PulseBadge::class, 'pulse_badge_id');
    }
}

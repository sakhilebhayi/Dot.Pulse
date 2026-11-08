<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PulseEvent extends Model
{
    protected $table = 'pulse_events';

    protected $fillable = ['user_id', 'community_id', 'title', 'description', 'type', 'url', 'starts_at', 'ends_at', 'rsvps_count'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function organiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(PulseEventRsvp::class);
    }
}

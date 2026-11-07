<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseMatch extends Model
{
    protected $table = 'pulse_matches';

    // The table has computed_at, seen_at and dismissed_at, and no created_at/updated_at.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'signal_id',
        'score',
        'reasons',
        'computed_at',
        'seen_at',
        'dismissed_at',
        'dismissed_reason',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'score' => 'float',
            'computed_at' => 'datetime',
            'seen_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(PulseSignal::class, 'signal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

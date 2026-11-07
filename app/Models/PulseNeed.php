<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseNeed extends Model
{
    protected $table = 'pulse_needs';

    protected $fillable = [
        'user_id',
        'entity_id',
        'kind',
        'title',
        'details',
        'tags',
        'budget_text',
        'needed_by',
        'place_id',
        'reach',
        'visibility',
        'status',
        'moderation',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'needed_by' => 'date',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(PulsePlace::class, 'place_id');
    }
}

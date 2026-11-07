<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseSignal extends Model
{
    protected $table = 'pulse_signals';

    protected $fillable = [
        'kind',
        'subject_type',
        'subject_id',
        'owner_id',
        'entity_id',
        'title',
        'excerpt',
        'topics',
        'skills',
        'place_id',
        'reach',
        'is_remote',
        'anonymous',
        'quality',
        'status',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'topics' => 'array',
            'skills' => 'array',
            'is_remote' => 'boolean',
            'anonymous' => 'boolean',
            'quality' => 'float',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(PulsePlace::class, 'place_id');
    }
}

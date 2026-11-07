<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PulseOffer extends Model
{
    use SoftDeletes;

    protected $table = 'pulse_offers';

    protected $fillable = [
        'entity_id',
        'created_by',
        'slug',
        'kind',
        'title',
        'summary',
        'description',
        'category',
        'tags',
        'price_mode',
        'amount_cents',
        'currency',
        'unit',
        'place_id',
        'service_area',
        'availability',
        'accepts_quotes',
        'status',
        'moderation',
        'media_ids',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'service_area' => 'array',
            'media_ids' => 'array',
            'accepts_quotes' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'amount_cents' => 'integer',
        ];
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PulseRole extends Model
{
    use SoftDeletes;

    protected $table = 'pulse_roles';

    protected $fillable = [
        'entity_id',
        'created_by',
        'slug',
        'title',
        'summary',
        'description',
        'employment_type',
        'work_mode',
        'seniority',
        'place_id',
        'skills',
        'pay_text',
        'apply_mode',
        'apply_url',
        'status',
        'moderation',
        'published_at',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'published_at' => 'datetime',
            'closes_at' => 'datetime',
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

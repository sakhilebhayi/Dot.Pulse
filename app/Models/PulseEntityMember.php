<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseEntityMember extends Model
{
    protected $table = 'pulse_entity_members';

    protected $fillable = [
        'entity_id',
        'user_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

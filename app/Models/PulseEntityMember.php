<?php

namespace App\Models;

use Database\Factories\PulseEntityMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseEntityMember extends Model
{
    /** @use HasFactory<PulseEntityMemberFactory> */
    use HasFactory;

    protected $table = 'pulse_entity_members';

    protected $fillable = [
        'entity_id',
        'user_id',
        'role',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

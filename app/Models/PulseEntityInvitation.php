<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An invitation to join a business page's team. The address is held only as a keyed hash. */
class PulseEntityInvitation extends Model
{
    protected $table = 'pulse_entity_invitations';

    protected $fillable = ['entity_id', 'email_hash', 'email_hint', 'role', 'invited_by', 'expires_at', 'accepted_at', 'declined_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Not yet answered, not taken back, not past its end. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('declined_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}

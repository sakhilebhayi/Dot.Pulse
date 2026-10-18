<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityJoinRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    protected $fillable = ['community_id', 'user_id', 'status', 'decided_by', 'decided_at'];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}

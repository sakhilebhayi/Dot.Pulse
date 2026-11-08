<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person blocking another. A block hides both ways: neither sees the other, and open lines are closed. */
class PulseBlock extends Model
{
    protected $table = 'pulse_blocks';

    protected $fillable = [
        'blocker_id',
        'blocked_id',
    ];

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }

    /** Blocks where the user is on either side. */
    public function scopeInvolving(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->id : $user;

        return $query->where(fn (Builder $q) => $q->where('blocker_id', $id)->orWhere('blocked_id', $id));
    }
}

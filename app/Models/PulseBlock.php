<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseBlock extends Model
{
    protected $table = 'pulse_blocks';

    protected $fillable = [
        'blocker_id',
        'blocked_id',
    ];

    protected function casts(): array
    {
        return [
        ];
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}

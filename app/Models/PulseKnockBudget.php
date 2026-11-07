<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's allowance of first contacts to strangers for the current window. Never purchasable. */
class PulseKnockBudget extends Model
{
    protected $table = 'pulse_knock_budgets';

    protected $fillable = ['user_id', 'window_started_at', 'spent', 'bonus', 'penalty'];

    protected function casts(): array
    {
        return [
            'window_started_at' => 'datetime',
            'spent' => 'integer',
            'bonus' => 'integer',
            'penalty' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

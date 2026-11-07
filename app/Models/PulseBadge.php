<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PulseBadge extends Model
{
    protected $table = 'pulse_badges';

    protected $fillable = ['key', 'label', 'icon', 'description', 'category'];

    public function userBadges(): HasMany
    {
        return $this->hasMany(PulseUserBadge::class);
    }
}

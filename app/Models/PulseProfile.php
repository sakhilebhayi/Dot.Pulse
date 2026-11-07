<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseProfile extends Model
{
    protected $fillable = [
        'user_id', 'headline', 'bio', 'avatar_url', 'cover_url', 'location',
        'website', 'skills', 'expertise_tags', 'role', 'community_points',
        'solutions_accepted', 'is_verified',
    ];

    protected $casts = [
        'skills' => 'array',
        'expertise_tags' => 'array',
        'is_verified' => 'boolean',
        'community_points' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addPoints(int $points): void
    {
        $this->increment('community_points', $points);
    }
}

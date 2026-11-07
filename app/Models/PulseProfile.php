<?php

namespace App\Models;

use Database\Factories\PulseProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PulseProfile extends Model
{
    /** @use HasFactory<PulseProfileFactory> */
    use HasFactory;

    public const DISCOVERABILITY = ['off', 'anonymous', 'open'];

    public const CONTACT_POLICIES = ['anyone', 'context', 'requests', 'nobody'];

    public const OPEN_TO = ['work', 'freelance', 'collab', 'mentoring', 'investing'];

    protected $fillable = [
        'user_id', 'headline', 'bio', 'avatar_url', 'cover_url', 'location',
        'website', 'skills', 'expertise_tags', 'role', 'community_points',
        'solutions_accepted', 'is_verified',
        'kind', 'discoverability', 'open_to', 'place_id', 'reach', 'show_place', 'experience_years',
        'languages', 'interests', 'achievements', 'work_history', 'contact_policy', 'onboarded_at',
        'age_confirmed_at', 'avatar_media_id', 'moderation',
    ];

    protected $casts = [
        'skills' => 'array',
        'expertise_tags' => 'array',
        'is_verified' => 'boolean',
        'community_points' => 'integer',
        'open_to' => 'array',
        'languages' => 'array',
        'interests' => 'array',
        'achievements' => 'array',
        'work_history' => 'array',
        'show_place' => 'boolean',
        'experience_years' => 'integer',
        'onboarded_at' => 'datetime',
        'age_confirmed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(PulsePlace::class, 'place_id');
    }

    public function signal(): HasOne
    {
        return $this->hasOne(PulseSignal::class, 'subject_id')->where('subject_type', 'profile');
    }

    /** Found by employers (anonymously or openly): needs the age confirmation and a choice other than off. */
    public function scopeDiscoverable(Builder $query): Builder
    {
        return $query->whereIn('discoverability', ['anonymous', 'open'])->whereNotNull('age_confirmed_at');
    }

    public function isAdultConfirmed(): bool
    {
        return $this->age_confirmed_at !== null;
    }

    public function isDiscoverable(): bool
    {
        return in_array($this->discoverability, ['anonymous', 'open'], true) && $this->isAdultConfirmed();
    }

    public function addPoints(int $points): void
    {
        $this->increment('community_points', $points);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An earned review: only after a confirmed outcome, one per person per line, blind until the other side has also
 * submitted or 14 days have passed. See App\Services\Pulse\Lines\ReviewService.
 */
class PulseReview extends Model
{
    public const BLIND_DAYS = 14;

    protected $table = 'pulse_reviews';

    protected $fillable = [
        'user_id', 'reviewable_type', 'reviewable_id', 'rating', 'body', 'is_verified', 'fraud_score',
        'conversation_id', 'reviewer_side', 'outcome', 'pseudonymous', 'moderation', 'revealed_at', 'held_for_ring',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_verified' => 'boolean',
            'pseudonymous' => 'boolean',
            'held_for_ring' => 'boolean',
            'fraud_score' => 'float',
            'revealed_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PulseConversation::class, 'conversation_id');
    }

    /** Visible to the public: revealed (both submitted) or older than the blind period, and not held or removed. */
    public function scopeRevealed(Builder $query): Builder
    {
        return $query->where('moderation', 'approved')
            ->where(fn (Builder $q) => $q->whereNotNull('revealed_at')
                ->orWhere('created_at', '<=', now()->subDays(self::BLIND_DAYS)));
    }
}

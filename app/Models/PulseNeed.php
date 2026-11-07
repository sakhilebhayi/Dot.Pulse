<?php

namespace App\Models;

use Database\Factories\PulseNeedFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A person's "looking for": a need, a supplier, a collaborator, an investor, a mentor, an introduction. */
class PulseNeed extends Model
{
    /** @use HasFactory<PulseNeedFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'pulse_needs';

    protected $fillable = [
        'user_id',
        'entity_id',
        'kind',
        'title',
        'details',
        'tags',
        'budget_text',
        'needed_by',
        'place_id',
        'reach',
        'visibility',
        'status',
        'moderation',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'needed_by' => 'date',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'open')
            ->where($query->qualifyColumn('moderation'), 'approved')
            ->where(fn (Builder $q) => $q->whereNull($query->qualifyColumn('expires_at'))->orWhere($query->qualifyColumn('expires_at'), '>', now()));
    }

    /** Needs are personal: the owner, platform moderators, and anyone for a live open need. Matched needs are reached through signals only. */
    public function scopeForViewer(Builder $query, ?User $viewer): Builder
    {
        if ($viewer?->isPlatformModerator()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($viewer) {
            $q->where(fn (Builder $live) => $live->live()->where('visibility', 'open'));

            if ($viewer !== null) {
                $q->orWhere('user_id', $viewer->id);
            }
        });
    }

    public function isLive(): bool
    {
        return ! $this->trashed()
            && $this->status === 'open'
            && $this->moderation === 'approved'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(PulsePlace::class, 'place_id');
    }

    public function signal(): HasOne
    {
        return $this->hasOne(PulseSignal::class, 'subject_id')->where('subject_type', 'need');
    }
}

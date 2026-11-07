<?php

namespace App\Models;

use Database\Factories\PulseOfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PulseOffer extends Model
{
    /** @use HasFactory<PulseOfferFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'pulse_offers';

    protected $fillable = [
        'entity_id',
        'created_by',
        'slug',
        'kind',
        'title',
        'summary',
        'description',
        'category',
        'tags',
        'price_mode',
        'amount_cents',
        'currency',
        'unit',
        'place_id',
        'service_area',
        'availability',
        'accepts_quotes',
        'status',
        'moderation',
        'media_ids',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'service_area' => 'array',
            'media_ids' => 'array',
            'accepts_quotes' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'amount_cents' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Public: live, approved, not expired, on a live page. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'live')
            ->where($query->qualifyColumn('moderation'), 'approved')
            ->where(fn (Builder $q) => $q->whereNull($query->qualifyColumn('expires_at'))->orWhere($query->qualifyColumn('expires_at'), '>', now()))
            ->whereHas('entity', fn (Builder $e) => $e->live());
    }

    /** What this viewer may see: live offers, plus every offer of pages they can manage listings on. */
    public function scopeForViewer(Builder $query, ?User $viewer): Builder
    {
        if ($viewer?->isPlatformModerator()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($viewer) {
            $q->where(fn (Builder $live) => $live->live());

            if ($viewer !== null) {
                $q->orWhereIn($q->qualifyColumn('entity_id'), PulseEntityMember::query()
                    ->where('user_id', $viewer->id)->whereIn('role', ['owner', 'admin', 'editor'])->select('entity_id'));
            }
        });
    }

    public function isLive(): bool
    {
        return ! $this->trashed()
            && $this->status === 'live'
            && $this->moderation === 'approved'
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && (bool) $this->entity?->isLive();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(PulsePlace::class, 'place_id');
    }

    public function signal(): HasOne
    {
        return $this->hasOne(PulseSignal::class, 'subject_id')->where('subject_type', 'offer');
    }
}

<?php

namespace App\Models;

use Database\Factories\PulseRoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PulseRole extends Model
{
    /** @use HasFactory<PulseRoleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'pulse_roles';

    protected $fillable = [
        'entity_id',
        'created_by',
        'slug',
        'title',
        'summary',
        'description',
        'employment_type',
        'work_mode',
        'seniority',
        'place_id',
        'skills',
        'pay_text',
        'apply_mode',
        'apply_url',
        'status',
        'moderation',
        'published_at',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'published_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'live')
            ->where($query->qualifyColumn('moderation'), 'approved')
            ->where(fn (Builder $q) => $q->whereNull($query->qualifyColumn('closes_at'))->orWhere($query->qualifyColumn('closes_at'), '>', now()))
            ->whereHas('entity', fn (Builder $e) => $e->live());
    }

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
            && ($this->closes_at === null || $this->closes_at->isFuture())
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
        return $this->hasOne(PulseSignal::class, 'subject_id')->where('subject_type', 'role');
    }
}

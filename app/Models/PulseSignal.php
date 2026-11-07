<?php

namespace App\Models;

use Database\Factories\PulseSignalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row in the discovery index. subject_type is a short name: offer, need, role, profile, post, entity.
 * `owner_id` exists so matching can exclude your own signals and honour blocks; it must never be shown for
 * an anonymous signal.
 */
class PulseSignal extends Model
{
    /** @use HasFactory<PulseSignalFactory> */
    use HasFactory;

    public const KINDS = ['offer', 'need', 'role', 'talent', 'news', 'ask', 'event', 'collab', 'business'];

    public const STATUSES = ['live', 'paused', 'expired', 'removed', 'held'];

    protected $table = 'pulse_signals';

    protected $fillable = [
        'kind',
        'subject_type',
        'subject_id',
        'owner_id',
        'entity_id',
        'title',
        'excerpt',
        'topics',
        'skills',
        'place_id',
        'reach',
        'is_remote',
        'anonymous',
        'quality',
        'status',
        'published_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'topics' => 'array',
            'skills' => 'array',
            'is_remote' => 'boolean',
            'anonymous' => 'boolean',
            'quality' => 'float',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** What discovery may show: live and not past its end. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'live')
            ->where(fn (Builder $q) => $q->whereNull($query->qualifyColumn('expires_at'))->orWhere($query->qualifyColumn('expires_at'), '>', now()));
    }

    public function scopeOfKind(Builder $query, string ...$kinds): Builder
    {
        return $query->whereIn($query->qualifyColumn('kind'), $kinds);
    }

    public function scopeForSubject(Builder $query, string $type, int $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(PulsePlace::class, 'place_id');
    }
}

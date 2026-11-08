<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An open door: a stated purpose, open for a day or a week, that strangers may knock on once. */
class PulseDoor extends Model
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const LAPSED = 'lapsed';

    protected $table = 'pulse_doors';

    protected $fillable = [
        'owner_id', 'entity_id', 'opened_by', 'purpose', 'length', 'status', 'signal_id',
        'opens_at', 'closes_at', 'knock_cap', 'knocks_count',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'knock_cap' => 'integer',
            'knocks_count' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(PulseSignal::class, 'signal_id');
    }

    public function knocks(): HasMany
    {
        return $this->hasMany(PulseConversation::class, 'door_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN && $this->closes_at->isFuture();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN)->where('closes_at', '>', now());
    }
}

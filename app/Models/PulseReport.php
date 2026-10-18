<?php

namespace App\Models;

use App\Enums\ReportReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PulseReport extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const DISMISSED = 'dismissed';

    protected $table = 'pulse_reports';

    protected $fillable = [
        'reporter_id', 'reportable_type', 'reportable_id', 'community_id',
        'reason', 'details', 'status', 'resolved_by', 'resolved_at', 'resolution',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function reasonEnum(): ?ReportReason
    {
        return ReportReason::tryFrom($this->reason);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    /**
     * Reports this moderator answers for: all of them for platform
     * moderators, otherwise only reports about content in communities the
     * user manages (a report about a profile has no community, so it is
     * platform-only).
     */
    public function scopeModeratableBy(Builder $query, User $user): Builder
    {
        if ($user->isPlatformModerator()) {
            return $query;
        }

        return $query->whereIn('community_id', $user->managedCommunityIds());
    }
}

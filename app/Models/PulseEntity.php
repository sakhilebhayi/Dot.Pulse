<?php

namespace App\Models;

use Database\Factories\PulseEntityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A business page: a seller, an employer or both. People belong to it with a role.
 * Public only when status is live AND its text has been approved (see ContentGate).
 */
class PulseEntity extends Model
{
    /** @use HasFactory<PulseEntityFactory> */
    use HasFactory;

    use SoftDeletes;

    public const MEMBER_ROLES = ['owner', 'admin', 'editor', 'responder'];

    public const STATUSES = ['draft', 'live', 'suspended', 'archived'];

    protected $table = 'pulse_entities';

    protected $fillable = [
        'slug',
        'name',
        'tagline',
        'about',
        'is_seller',
        'is_employer',
        'industry',
        'place_id',
        'service_area',
        'website',
        'public_email',
        'public_phone',
        'logo_media_id',
        'cover_media_id',
        'culture',
        'team_size',
        'founded_year',
        'registration_number',
        'status',
        'contact_policy',
        'hiring_signal',
        'hiring_skills',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_seller' => 'boolean',
            'is_employer' => 'boolean',
            'service_area' => 'array',
            'culture' => 'array',
            'hiring_skills' => 'array',
            'claims' => 'array',
            'infodot_verification' => 'array',
            'verified_at' => 'datetime',
            'followers_count' => 'integer',
            'founded_year' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Public: live and its text approved. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'live')->where($query->qualifyColumn('moderation'), 'approved');
    }

    /** What this viewer may see: live pages, plus pages they belong to; platform moderators see everything. */
    public function scopeForViewer(Builder $query, ?User $viewer): Builder
    {
        if ($viewer?->isPlatformModerator()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($viewer) {
            $q->where(fn (Builder $live) => $live->where('status', 'live')->where('moderation', 'approved'));

            if ($viewer !== null) {
                $q->orWhereIn('id', PulseEntityMember::query()->where('user_id', $viewer->id)->select('entity_id'));
            }
        });
    }

    public function isLive(): bool
    {
        return ! $this->trashed() && $this->status === 'live' && $this->moderation === 'approved';
    }

    /** The viewer's role on this page (owner, admin, editor, responder) or null. */
    public function memberRole(?User $user): ?string
    {
        if ($user === null || ! $this->exists) {
            return null;
        }

        return $this->members()->where('user_id', $user->id)->value('role');
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(PulsePlace::class, 'place_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(PulseEntityMember::class, 'entity_id');
    }

    public function owners(): HasMany
    {
        return $this->members()->where('role', 'owner');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pulse_entity_members', 'entity_id', 'user_id')->withPivot('role')->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pulse_entity_followers', 'entity_id', 'user_id')->withTimestamps();
    }

    public function offers(): HasMany
    {
        return $this->hasMany(PulseOffer::class, 'entity_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(PulseRole::class, 'entity_id');
    }

    public function needs(): HasMany
    {
        return $this->hasMany(PulseNeed::class, 'entity_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(PulseSignal::class, 'entity_id');
    }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(PulseMedia::class, 'logo_media_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(PulseMedia::class, 'cover_media_id');
    }
}

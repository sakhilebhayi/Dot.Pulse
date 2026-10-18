<?php

namespace App\Models;

use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Visibility lives here and nowhere else.
 *
 * - public: anyone signed in can see the community, read its posts and join
 *   with one click.
 * - anything else (private, enterprise): only members -- plus the creator --
 *   can see the page or its posts. Everyone else can see that the community
 *   exists in the directory (name and industry) and can ask to join; a
 *   manager approves or denies.
 *
 * Every query that lists posts goes through PulsePost::scopeVisibleTo(),
 * which encodes the same rule in SQL. Keep the two in step.
 */
class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory;

    public const MANAGER_ROLES = ['admin', 'moderator'];

    protected $fillable = [
        'team_id', 'created_by', 'name', 'slug', 'description',
        'avatar_url', 'banner_url', 'industry', 'visibility', 'members_count',
    ];

    protected $casts = [
        'members_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CommunityMembership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'community_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(CommunityJoinRequest::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CommunityRule::class)->orderBy('sort_order');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(PulsePost::class);
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function isPrivate(): bool
    {
        return ! $this->isPublic();
    }

    public function isMember(?User $user): bool
    {
        return $user !== null
            && $this->memberships()->where('user_id', $user->id)->exists();
    }

    /** Kept for existing callers; same as isMember(). */
    public function hasMember(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * A manager approves membership requests and moderates this
     * community's posts: the creator, or a member whose role is admin or
     * moderator.
     */
    public function isManager(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->created_by === $user->id) {
            return true;
        }

        return $this->memberships()
            ->where('user_id', $user->id)
            ->whereIn('role', self::MANAGER_ROLES)
            ->exists();
    }

    public function isVisibleTo(?User $user): bool
    {
        if ($this->isPublic()) {
            return true;
        }

        return $user !== null
            && ($this->created_by === $user->id || $this->isMember($user));
    }

    /**
     * Users who get notified about, and may decide, membership requests.
     *
     * @return Collection<int, User>
     */
    public function managers(): Collection
    {
        $ids = $this->memberships()
            ->whereIn('role', self::MANAGER_ROLES)
            ->pluck('user_id')
            ->push($this->created_by)
            ->unique();

        return User::whereKey($ids)->get();
    }

    /**
     * Idempotent: members_count only moves when a row was really inserted,
     * and the insert and the increment commit together.
     */
    public function addMember(User $user, string $role = 'member'): bool
    {
        return DB::transaction(function () use ($user, $role) {
            $membership = CommunityMembership::firstOrCreate(
                ['community_id' => $this->id, 'user_id' => $user->id],
                ['role' => $role],
            );

            if ($membership->wasRecentlyCreated) {
                static::whereKey($this->id)->increment('members_count');
                $this->members_count = (int) $this->members_count + 1;
            }

            return $membership->wasRecentlyCreated;
        });
    }

    /**
     * Only decrements when a membership row was actually deleted, so
     * leaving a community you are not in cannot push the count down.
     */
    public function removeMember(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $deleted = CommunityMembership::where('community_id', $this->id)
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted > 0) {
                static::whereKey($this->id)->where('members_count', '>', 0)->decrement('members_count');
                $this->members_count = max(0, (int) $this->members_count - 1);
            }

            return $deleted > 0;
        });
    }
}

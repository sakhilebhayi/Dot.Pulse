<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function pulseProfile(): HasOne
    {
        return $this->hasOne(PulseProfile::class);
    }

    /**
     * pulse_followers has existed since the platform's first migration
     * (unique(follower_id, following_id)) with no relation ever declared
     * on User and no UI reading or writing it — README advertises
     * "follow users and communities" but nothing let a user follow
     * anyone.
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'pulse_followers', 'following_id', 'follower_id')->withTimestamps();
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'pulse_followers', 'follower_id', 'following_id')->withTimestamps();
    }

    /**
     * Platform-wide moderation (PulseProfile.role). Distinct from being a
     * moderator of one community, which is a community_memberships.role.
     */
    public function isPlatformModerator(): bool
    {
        return in_array($this->pulseProfile?->role, ['moderator', 'admin'], true);
    }

    /**
     * Ids of the communities this user manages: created, or holds the
     * admin/moderator role in.
     *
     * @return list<int>
     */
    public function managedCommunityIds(): array
    {
        $viaRole = CommunityMembership::where('user_id', $this->id)
            ->whereIn('role', Community::MANAGER_ROLES)
            ->pluck('community_id');

        return Community::where('created_by', $this->id)
            ->pluck('id')
            ->merge($viaRole)
            ->unique()
            ->values()
            ->all();
    }

    /** Whether the moderation queue has anything this user may act on. */
    public function canAccessModeration(): bool
    {
        return $this->isPlatformModerator() || $this->managedCommunityIds() !== [];
    }

    public function isFollowing(self $user): bool
    {
        return $this->following()->where('users.id', $user->id)->exists();
    }

    /**
     * Mirrors Community::members() -- lets HomeFeed's Following tab merge
     * in posts from communities the viewer has joined, not just posts
     * from people they follow.
     */
    public function joinedCommunities(): BelongsToMany
    {
        return $this->belongsToMany(Community::class, 'community_memberships')->withTimestamps();
    }

    /**
     * Overrides Laravel's default `App.Models.User.{id}` broadcast channel
     * name so it matches this codebase's existing short-form convention
     * (see App\Providers\BroadcastServiceProvider's post.{postId} channel)
     * instead of mixing two naming schemes. The optional $notification
     * parameter matches Laravel's real call site --
     * Illuminate\Notifications\Events\BroadcastNotificationCreated::channelName()
     * calls this with the notification instance -- even though it's
     * unused today.
     */
    public function receivesBroadcastNotificationsOn(?Notification $notification = null): string
    {
        return 'user.'.$this->id;
    }
}

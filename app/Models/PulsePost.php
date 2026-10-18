<?php

namespace App\Models;

use Database\Factories\PulsePostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PulsePost extends Model
{
    /** @use HasFactory<PulsePostFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'pulse_posts';

    protected $fillable = [
        'user_id', 'community_id', 'team_id', 'type', 'title', 'body',
        'status', 'is_pinned', 'views_count', 'comments_count', 'reactions_count', 'ai_relevance_score',
        'published_at', 'edited_at',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'ai_relevance_score' => 'float',
        'published_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public static array $types = [
        'discussion', 'announcement', 'question', 'idea', 'bug_report', 'release',
        'success_story', 'showcase', 'tutorial', 'agent', 'integration',
        'event', 'article', 'poll', 'video', 'job', 'marketplace',
    ];

    protected static function booted(): void
    {
        // Whatever path moves a post to 'published' stamps the time once.
        static::saving(function (self $post) {
            if ($post->status === 'published' && $post->published_at === null) {
                $post->published_at = now();
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function enrichment(): HasOne
    {
        return $this->hasOne(PulsePostEnrichment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PulseComment::class)->whereNull('parent_id')->latest();
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(PulseComment::class);
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(PulseReaction::class, 'reactable');
    }

    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(PulseHashtag::class, 'pulse_post_hashtag', 'pulse_post_id', 'pulse_hashtag_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * The one SQL statement of the visibility rule (see Community): a post
     * is visible when it has no community, or its community is public, or
     * the viewer is a member. Does not look at status; combine with
     * published() for anything listed to other people.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->whereNull($q->qualifyColumn('community_id'))
                ->orWhereIn($q->qualifyColumn('community_id'), function ($sub) {
                    $sub->select('id')->from('communities')->where('visibility', 'public');
                });

            if ($user !== null) {
                $q->orWhereIn($q->qualifyColumn('community_id'), function ($sub) use ($user) {
                    $sub->select('community_id')->from('community_memberships')->where('user_id', $user->id);
                });
            }
        });
    }

    public function scopeFeed(Builder $query): Builder
    {
        return $query->published()->orderByDesc('ai_relevance_score')->orderByDesc('created_at');
    }

    /**
     * Toggle the viewer's reaction. The delete's row count decides which way
     * the counter moves, so two concurrent clicks cannot both decrement (or
     * both increment, thanks to the unique index). Returns true when the
     * reaction now exists.
     */
    public function toggleReaction(User $user, string $emoji): bool
    {
        return DB::transaction(function () use ($user, $emoji) {
            $removed = $this->reactions()->where('user_id', $user->id)->delete();

            if ($removed > 0) {
                static::whereKey($this->id)->where('reactions_count', '>', 0)->decrement('reactions_count');

                return false;
            }

            try {
                $this->reactions()->create(['user_id' => $user->id, 'emoji' => $emoji]);
            } catch (UniqueConstraintViolationException) {
                return true;
            }

            static::whereKey($this->id)->increment('reactions_count');

            return true;
        });
    }

    /**
     * Recompute comments_count from the rows (exact, idempotent) rather
     * than adding and subtracting guesses when comments and replies are
     * removed together.
     */
    public static function syncCommentsCount(int $postId): void
    {
        static::whereKey($postId)->update([
            'comments_count' => DB::raw(
                '(select count(*) from pulse_comments where pulse_comments.pulse_post_id = pulse_posts.id and pulse_comments.deleted_at is null)'
            ),
        ]);
    }
}

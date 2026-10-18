<?php

namespace App\Models;

use Database\Factories\PulseCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PulseComment extends Model
{
    /** @use HasFactory<PulseCommentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'pulse_comments';

    protected $fillable = [
        'pulse_post_id', 'user_id', 'parent_id', 'body', 'is_solution', 'reactions_count', 'edited_at',
    ];

    protected $casts = [
        'is_solution' => 'boolean',
        'edited_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Soft-deleting a comment takes its replies with it, so a reply is
        // never left hanging under a parent nobody can see.
        static::deleting(function (self $comment) {
            if (! $comment->isForceDeleting()) {
                $comment->replies()->get()->each->delete();
            }
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(PulsePost::class, 'pulse_post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->latest();
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(PulseReaction::class, 'reactable');
    }

    /** Soft-delete this comment and its replies, then fix the post's count. */
    public function remove(): void
    {
        DB::transaction(function () {
            $this->delete();
            PulsePost::syncCommentsCount($this->pulse_post_id);
        });
    }

    /** See PulsePost::toggleReaction(). */
    public function toggleReaction(User $user, string $emoji): bool
    {
        return DB::transaction(function () use ($user, $emoji) {
            $removed = $this->reactions()->where('user_id', $user->id)->delete();

            if ($removed > 0) {
                static::whereKey($this->id)->where('reactions_count', '>', 0)->decrement('reactions_count');

                return false;
            }

            try {
                // A nested transaction is a savepoint: on Postgres a failed
                // insert would otherwise abort the whole outer transaction.
                DB::transaction(fn () => $this->reactions()->create(['user_id' => $user->id, 'emoji' => $emoji]));
            } catch (UniqueConstraintViolationException) {
                return true;
            }

            static::whereKey($this->id)->increment('reactions_count');

            return true;
        });
    }
}

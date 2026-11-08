<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A muted topic, business page or person. Quiet and one way: the other side is never told. */
class PulseMute extends Model
{
    public const KINDS = ['topic', 'entity', 'user'];

    protected $table = 'pulse_mutes';

    protected $fillable = [
        'user_id',
        'kind',
        'target',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

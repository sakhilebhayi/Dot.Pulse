<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A block between a person and a business page. side=user: the person blocked the page. side=entity: the page blocked the person. */
class PulseEntityBlock extends Model
{
    public const BY_USER = 'user';

    public const BY_ENTITY = 'entity';

    protected $table = 'pulse_entity_blocks';

    protected $fillable = ['entity_id', 'user_id', 'side', 'created_by'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseMessage extends Model
{
    public const KINDS = ['text', 'quote_request', 'quote', 'system'];

    protected $table = 'pulse_messages';

    protected $fillable = [
        'pulse_conversation_id', 'user_id', 'body', 'kind', 'meta', 'media_id', 'side', 'erased_at',
    ];

    /** The author is not serialised: for an anonymous professional that would name them. Use LineService::thread(). */
    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'erased_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PulseConversation::class, 'pulse_conversation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

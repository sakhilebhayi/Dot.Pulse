<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseNotificationPref extends Model
{
    protected $table = 'pulse_notification_prefs';

    protected $fillable = [
        'user_id',
        'channels',
        'categories',
        'frequency',
        'digest',
        'digest_hour',
        'timezone',
        'last_digest_at',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'categories' => 'array',
            'digest' => 'boolean',
            'last_digest_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

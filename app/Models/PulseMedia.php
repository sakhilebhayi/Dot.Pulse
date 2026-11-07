<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A re-encoded image (three sizes) on the private disk. Never read a path from here to build a public URL:
 * App\Services\Pulse\Entities\MediaService::url() returns the authorised route.
 */
class PulseMedia extends Model
{
    protected $table = 'pulse_media_files';

    protected $fillable = [
        'user_id', 'purpose', 'entity_id', 'conversation_id', 'disk', 'mime', 'width', 'height', 'bytes', 'variants', 'sha256',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(PulseEntity::class, 'entity_id');
    }

    /** @return array{path: string, width: int, height: int, bytes: int}|null */
    public function variant(string $size): ?array
    {
        return $this->variants[$size] ?? null;
    }
}

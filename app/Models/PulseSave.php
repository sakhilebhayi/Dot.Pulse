<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PulseSave extends Model
{
    protected $table = 'pulse_saves';

    protected $fillable = [
        'user_id',
        'saveable_type',
        'saveable_id',
    ];

    protected function casts(): array
    {
        return [
        ];
    }
}

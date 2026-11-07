<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PulseMute extends Model
{
    protected $table = 'pulse_mutes';

    protected $fillable = [
        'user_id',
        'kind',
        'target',
    ];

    protected function casts(): array
    {
        return [
        ];
    }
}

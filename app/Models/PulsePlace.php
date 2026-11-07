<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PulsePlace extends Model
{
    protected $table = 'pulse_places';

    public $timestamps = false;

    protected $fillable = [
        'level',
        'country_code',
        'region',
        'city',
        'name',
        'slug',
        'lat',
        'lng',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Relations only; the gazetteer data and lookups belong to the discovery agent. */
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

    public function entities(): HasMany
    {
        return $this->hasMany(PulseEntity::class, 'place_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(PulseOffer::class, 'place_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(PulseRole::class, 'place_id');
    }

    public function needs(): HasMany
    {
        return $this->hasMany(PulseNeed::class, 'place_id');
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(PulseProfile::class, 'place_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(PulseSignal::class, 'place_id');
    }
}

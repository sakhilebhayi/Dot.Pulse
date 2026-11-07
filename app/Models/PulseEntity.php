<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PulseEntity extends Model
{
    use SoftDeletes;

    protected $table = 'pulse_entities';

    protected $fillable = [
        'slug',
        'name',
        'tagline',
        'about',
        'is_seller',
        'is_employer',
        'industry',
        'place_id',
        'service_area',
        'website',
        'public_email',
        'public_phone',
        'logo_media_id',
        'cover_media_id',
        'culture',
        'team_size',
        'founded_year',
        'registration_number',
        'status',
        'contact_policy',
        'hiring_signal',
        'hiring_skills',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_seller' => 'boolean',
            'is_employer' => 'boolean',
            'service_area' => 'array',
            'culture' => 'array',
            'hiring_skills' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(PulsePlace::class, 'place_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(PulseEntityMember::class, 'entity_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(PulseOffer::class, 'entity_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(PulseRole::class, 'entity_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PulseMarketplaceItem extends Model
{
    protected $table = 'pulse_marketplace_items';

    protected $fillable = ['user_id', 'title', 'description', 'category', 'version', 'changelog', 'installs_count', 'avg_rating', 'is_published'];

    protected $casts = ['changelog' => 'array', 'is_published' => 'boolean'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function installs(): HasMany
    {
        return $this->hasMany(PulseMarketplaceInstall::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseMarketplaceInstall extends Model
{
    protected $table = 'pulse_marketplace_installs';

    protected $fillable = ['pulse_marketplace_item_id', 'user_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PulseMarketplaceItem::class, 'pulse_marketplace_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

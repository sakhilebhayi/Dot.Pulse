<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PulseAuditEntry extends Model
{
    protected $table = 'pulse_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'meta',
        'ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}

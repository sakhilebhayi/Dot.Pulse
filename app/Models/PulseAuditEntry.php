<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the audit log. Append only: a row is written once and never changed or removed. The model refuses
 * updates and deletes, and on SQLite and PostgreSQL a trigger refuses them too (the one exception is the actor and
 * ip hash being nulled when a person is erased, see Lines\AuditLog::forgetActor).
 */
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
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit log is append only.'));
        static::deleting(fn () => throw new LogicException('The audit log is append only.'));
    }

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

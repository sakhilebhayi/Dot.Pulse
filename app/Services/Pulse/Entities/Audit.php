<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseAuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;

/**
 * One place that appends to the audit log. Uses the Lines agent's AuditLog when it exists, and writes the same
 * row itself otherwise, so this module never depends on the order in which branches merge.
 */
class Audit
{
    private const LINES_AUDIT = 'App\\Services\\Pulse\\Lines\\AuditLog';

    /** @param array<string, mixed> $meta */
    public static function record(?User $actor, string $action, ?Model $subject = null, array $meta = []): void
    {
        $class = self::LINES_AUDIT;

        if (class_exists($class) && method_exists($class, 'record')) {
            $method = new ReflectionMethod($class, 'record');
            $method->isStatic() ? $class::record($actor, $action, $subject, $meta) : app($class)->record($actor, $action, $subject, $meta);

            return;
        }

        PulseAuditEntry::create([
            'actor_id' => $actor?->id,
            'action' => substr($action, 0, 48),
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip_hash' => null,
        ]);
    }
}

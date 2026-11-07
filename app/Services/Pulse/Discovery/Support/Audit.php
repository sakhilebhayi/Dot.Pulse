<?php

namespace App\Services\Pulse\Discovery\Support;

use App\Models\PulseAuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit line for the writes this module makes. Uses the shared AuditLog service when the Lines module
 * is present and writes the same table directly otherwise, so there is one place this can drift: the table.
 */
final class Audit
{
    /** @param array<string, mixed> $meta */
    public static function record(?User $actor, string $action, ?Model $subject = null, array $meta = []): void
    {
        $shared = 'App\\Services\\Pulse\\Lines\\AuditLog';

        try {
            if (class_exists($shared) && method_exists($shared, 'record')) {
                $shared::record($actor, $action, $subject, $meta);

                return;
            }

            PulseAuditEntry::create([
                'actor_id' => $actor?->id,
                'action' => substr($action, 0, 48),
                'subject_type' => $subject ? SubjectTypes::normalise(get_class($subject)) : null,
                'subject_id' => $subject?->getKey(),
                'meta' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            // An audit failure must never break discovery; it is reported, not swallowed.
            report($e);
        }
    }
}

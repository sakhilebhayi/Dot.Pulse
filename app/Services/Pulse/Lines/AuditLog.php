<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseAuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The append only record of what happened and who did it. There is no update and no delete path. The one change a
 * row can ever receive is forgetActor(), used when a person is erased: it nulls the actor and the ip hash and
 * leaves the action, subject and time, which POPIA allows us to keep as a record of security and moderation events
 * (the data is no longer about an identifiable person). On SQLite and PostgreSQL a trigger enforces this.
 * Keep `meta` free of free text from users and of anything identifying an anonymous professional.
 */
class AuditLog
{
    public static function record(?User $actor, string $action, ?Model $subject = null, array $meta = []): void
    {
        PulseAuditEntry::create([
            'actor_id' => $actor?->id,
            'action' => mb_substr($action, 0, 48),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta === [] ? null : $meta,
            'ip_hash' => self::ipHash(),
            'created_at' => now(),
        ]);
    }

    /** Null the actor on every row written by this person (erasure). The only permitted change to the log. */
    public static function forgetActor(User|int $user): int
    {
        $id = $user instanceof User ? $user->id : $user;

        return DB::table('pulse_audit_log')->where('actor_id', $id)->update(['actor_id' => null, 'ip_hash' => null]);
    }

    private static function ipHash(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        $ip = request()->ip();

        return $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
    }
}

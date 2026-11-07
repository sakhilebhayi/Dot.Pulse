<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseModerationLog;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * A platform moderator's decision on a listing or page. Changing the moderation state re-syncs the signal
 * through the observers: approve = live, hold = held, remove = gone from discovery and not editable by the owner.
 * Every decision lands in the moderation log and the audit log.
 */
class ListingModeration
{
    public const DECISIONS = ['approved', 'held', 'removed'];

    public function decide(User $moderator, Model $subject, string $decision, ?string $rationale = null): void
    {
        abort_unless($moderator->isPlatformModerator(), 403);

        if (! in_array($decision, self::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'Approve, hold or remove.']);
        }

        if (! ($subject instanceof PulseEntity || $subject instanceof PulseOffer || $subject instanceof PulseRole || $subject instanceof PulseNeed)) {
            throw ValidationException::withMessages(['subject' => 'Only pages, offers, roles and needs are decided here.']);
        }

        $subject->forceFill(['moderation' => $decision])->save();

        PulseModerationLog::create([
            'moderator_id' => $moderator->id,
            'target_type' => $subject->getMorphClass(),
            'target_id' => $subject->getKey(),
            'action' => match ($decision) {
                'approved' => 'approve',
                'held' => 'flag',
                'removed' => 'remove',
            },
            'rationale' => $rationale,
            'is_ai_decision' => false,
        ]);

        Audit::record($moderator, 'moderation.'.$decision, $subject, ['rationale' => $rationale]);
    }

    /** A moderator suspends a page (it leaves discovery and cannot be restored by its owner) or reinstates it. */
    public function suspend(User $moderator, PulseEntity $entity, ?string $rationale = null): void
    {
        abort_unless($moderator->isPlatformModerator(), 403);

        $entity->update(['status' => 'suspended']);
        Audit::record($moderator, 'entity.suspend', $entity, ['rationale' => $rationale]);
    }

    public function reinstate(User $moderator, PulseEntity $entity): void
    {
        abort_unless($moderator->isPlatformModerator(), 403);

        $entity->update(['status' => 'live']);
        Audit::record($moderator, 'entity.reinstate', $entity);
    }
}

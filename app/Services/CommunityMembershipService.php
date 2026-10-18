<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityJoinRequest;
use App\Models\User;
use App\Notifications\CommunityJoinDecision;
use App\Notifications\CommunityJoinRequested;
use App\Support\PulseThrottle;
use Illuminate\Support\Facades\DB;

/**
 * Join rules in one place so the directory and the community page cannot
 * drift: public communities take one click, everything else goes through a
 * request a manager decides.
 */
class CommunityMembershipService
{
    public const JOINED = 'joined';

    public const REQUESTED = 'requested';

    public const ALREADY_MEMBER = 'already_member';

    public const ALREADY_REQUESTED = 'already_requested';

    public const COOLING_DOWN = 'cooling_down';

    public function join(User $user, Community $community): string
    {
        if ($community->isMember($user)) {
            return self::ALREADY_MEMBER;
        }

        if ($community->isPublic()) {
            $community->addMember($user);

            return self::JOINED;
        }

        return $this->request($user, $community);
    }

    public function request(User $user, Community $community): string
    {
        PulseThrottle::hit('join_request');

        $existing = CommunityJoinRequest::where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing?->isPending()) {
            return self::ALREADY_REQUESTED;
        }

        if ($existing?->status === CommunityJoinRequest::DENIED
            && $existing->decided_at?->gt(now()->subDays(config('pulse.join_request_cooldown_days')))) {
            return self::COOLING_DOWN;
        }

        CommunityJoinRequest::updateOrCreate(
            ['community_id' => $community->id, 'user_id' => $user->id],
            ['status' => CommunityJoinRequest::PENDING, 'decided_by' => null, 'decided_at' => null],
        );

        foreach ($community->managers() as $manager) {
            $this->notifySafely($manager, new CommunityJoinRequested($community, $user));
        }

        return self::REQUESTED;
    }

    public function cancelRequest(User $user, Community $community): void
    {
        CommunityJoinRequest::where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->where('status', CommunityJoinRequest::PENDING)
            ->delete();
    }

    public function approve(CommunityJoinRequest $request, User $decidedBy): void
    {
        DB::transaction(function () use ($request, $decidedBy) {
            $request->update([
                'status' => CommunityJoinRequest::APPROVED,
                'decided_by' => $decidedBy->id,
                'decided_at' => now(),
            ]);
            $request->community->addMember($request->user);
        });

        $this->notifySafely($request->user, new CommunityJoinDecision($request->community, true));
    }

    public function deny(CommunityJoinRequest $request, User $decidedBy): void
    {
        $request->update([
            'status' => CommunityJoinRequest::DENIED,
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
        ]);

        $this->notifySafely($request->user, new CommunityJoinDecision($request->community, false));
    }

    /**
     * The database row is the source of truth; a broadcast hiccup (Reverb
     * down) must not roll back or hide the decision.
     */
    private function notifySafely(User $to, object $notification): void
    {
        try {
            $to->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

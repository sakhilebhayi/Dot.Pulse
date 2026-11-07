<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseBlock;
use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseEntityBlock;
use App\Models\PulseEntityMember;
use App\Models\PulseMessage;
use App\Models\PulseMute;
use App\Models\PulseNotificationPref;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Erases what Pulse holds about a person when they ask (POPIA right to deletion), without breaking the other
 * side's records of what happened.
 *
 * Retention: messages stay in the other party's thread with the body replaced ("deleted") so the thread still
 * reads; reviews the person wrote are anonymised and kept (they are part of another party's record, flagged
 * pseudonymous, author unlinked at account deletion); confirmed outcomes stay (the other side's result);
 * audit rows are kept for security and moderation with the actor and ip hash nulled, which is the one change the
 * append only log allows; reports stay for moderators. Profile text, discoverability, talent cards, needs, doors,
 * saves, mutes, blocks the person made, preferences, matches and Pulse notifications are removed. Blocks OTHER
 * people made against this person are kept so a blocked abuser cannot return by deleting and recreating.
 * Pages where the person is the only owner are left for them to hand over first and reported in the result.
 * This does not delete the user account itself: that stays with the account deletion flow.
 */
class PrivacyEraser
{
    /** @return array<string, int|list<string>> counts of what was removed or anonymised */
    public static function erase(User $user): array
    {
        return app(self::class)->run($user);
    }

    /** @return array<string, int|list<string>> */
    public function run(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $result = [];

            // Messages: keep the shape of the thread, lose the words.
            $result['messages'] = PulseMessage::query()->where('user_id', $user->id)->whereNull('erased_at')
                ->update(['body' => 'This message was deleted.', 'meta' => null, 'media_id' => null, 'erased_at' => now()]);

            // Open lines end; the other side is not left waiting.
            $result['lines_closed'] = PulseConversation::query()
                ->whereIn('status', [PulseConversation::REQUESTED, PulseConversation::OPEN])
                ->where(fn ($q) => $q->where('initiator_id', $user->id)->orWhere('recipient_id', $user->id))
                ->update(['status' => PulseConversation::CLOSED, 'closed_reason' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);

            // Findability: nothing about the profile stays discoverable or readable.
            $result['signals'] = PulseSignal::query()->where('owner_id', $user->id)
                ->update(['status' => 'removed', 'updated_at' => now()]);
            $result['needs_removed'] = DB::table('pulse_needs')->where('user_id', $user->id)->update(['status' => 'removed', 'details' => null, 'updated_at' => now()]);
            DB::table('pulse_profiles')->where('user_id', $user->id)->update([
                'headline' => null, 'bio' => null, 'avatar_url' => null, 'cover_url' => null, 'location' => null,
                'website' => null, 'skills' => null, 'expertise_tags' => null, 'open_to' => null, 'languages' => null,
                'interests' => null, 'achievements' => null, 'work_history' => null, 'experience_years' => null,
                'discoverability' => 'off', 'show_place' => false, 'contact_policy' => 'nobody', 'place_id' => null,
                'updated_at' => now(),
            ]);

            $result['doors'] = PulseDoor::query()->where('owner_id', $user->id)->where('status', PulseDoor::OPEN)->update(['status' => PulseDoor::CLOSED, 'updated_at' => now()]);

            // What they set up for themselves.
            $result['saves'] = PulseSave::query()->where('user_id', $user->id)->delete();
            $result['mutes'] = PulseMute::query()->where('user_id', $user->id)->delete();
            $result['blocks_made'] = PulseBlock::query()->where('blocker_id', $user->id)->delete()
                + PulseEntityBlock::query()->where('user_id', $user->id)->where('side', PulseEntityBlock::BY_USER)->delete();
            PulseNotificationPref::query()->where('user_id', $user->id)->delete();
            DB::table('pulse_matches')->where('user_id', $user->id)->delete();
            DB::table('pulse_entity_followers')->where('user_id', $user->id)->delete();
            $result['notifications'] = DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)
                ->where('type', \App\Notifications\PulseNotice::class)->delete();
            DB::table('pulse_knock_budgets')->where('user_id', $user->id)->delete();

            // Reviews they wrote: unlinked from a name, kept as the other side's record.
            $result['reviews'] = DB::table('pulse_reviews')->where('user_id', $user->id)->update(['pseudonymous' => true, 'updated_at' => now()]);

            // Pages: leave every page that has another owner; report the ones that do not.
            $stuck = [];
            foreach (PulseEntityMember::query()->where('user_id', $user->id)->get() as $m) {
                $onlyOwner = $m->role === 'owner'
                    && PulseEntityMember::query()->where('entity_id', $m->entity_id)->where('role', 'owner')->count() <= 1;

                if ($onlyOwner) {
                    $stuck[] = (string) ($m->entity?->slug ?? $m->entity_id);
                    continue;
                }

                $m->delete();
            }
            $result['pages_needing_an_owner'] = $stuck;

            // The audit trail stays, with the person taken out of it.
            $result['audit_rows_anonymised'] = AuditLog::forgetActor($user);
            AuditLog::record(null, 'privacy.erase', $user, ['messages' => $result['messages']]);

            return $result;
        });
    }
}

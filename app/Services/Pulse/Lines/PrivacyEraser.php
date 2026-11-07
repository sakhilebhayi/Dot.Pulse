<?php

namespace App\Services\Pulse\Lines;

use App\Models\CommunityJoinRequest;
use App\Models\CommunityMembership;
use App\Models\PulseBlock;
use App\Models\PulseComment;
use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseEntityBlock;
use App\Models\PulseEntityMember;
use App\Models\PulseEventRsvp;
use App\Models\PulseFollower;
use App\Models\PulseMedia;
use App\Models\PulseMessage;
use App\Models\PulseMute;
use App\Models\PulseNotificationPref;
use App\Models\PulsePollVote;
use App\Models\PulsePost;
use App\Models\PulseReaction;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use App\Models\PulseUserBadge;
use App\Models\User;
use App\Notifications\PulseNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Erases what Pulse holds about a person when they ask (POPIA right to deletion), without breaking the other
 * side's records of what happened.
 *
 * Retention: messages stay in the other party's thread with the body replaced ("deleted") so the thread still
 * reads; reviews the person wrote are anonymised and kept (they are part of another party's record, flagged
 * pseudonymous, author unlinked at account deletion); confirmed outcomes stay (the other side's result);
 * audit rows are kept for security and moderation with the actor and ip hash nulled, which is the one change the
 * append only log allows; reports stay for moderators. Profile text, discoverability, talent cards, needs, doors,
 * saves, mutes, blocks the person made, preferences, matches and Pulse notifications are removed, and so are the
 * photos they uploaded (their avatar and message photos: the files are deleted from disk), the enquiry card they
 * wrote on a line, their community posts, comments, reactions, follows and memberships, and their name in the
 * notifications other people hold about them. Blocks OTHER
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

            // The enquiry card the person wrote on a line is theirs; the other side keeps the thread and the listing.
            $result['cards'] = PulseConversation::query()->where('initiator_id', $user->id)->whereNotNull('card')->update(['card' => null]);

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

            $result['media'] = $this->deleteMedia($user);
            $result['community'] = $this->eraseCommunityContent($user);
            $this->scrubName($user);

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
                ->where('type', PulseNotice::class)->delete();
            DB::table('pulse_knock_budgets')->where('user_id', $user->id)->delete();
            $result['invitations'] = DB::table('pulse_entity_invitations')->where('email_hash', \App\Services\Pulse\Entities\InvitationService::hash((string) $user->email))->delete();

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

    /** Photos the person uploaded for themselves (avatar, message photos): rows and files. A page's own images stay with the page. */
    private function deleteMedia(User $user): int
    {
        $count = 0;

        foreach (PulseMedia::query()->where('user_id', $user->id)->whereIn('purpose', ['avatar', 'message'])->get() as $media) {
            foreach ((array) $media->variants as $variant) {
                try {
                    Storage::disk($media->disk)->delete($variant['path']);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            DB::table('pulse_profiles')->where('avatar_media_id', $media->id)->update(['avatar_media_id' => null]);
            DB::table('pulse_messages')->where('media_id', $media->id)->update(['media_id' => null]);
            $media->delete();
            $count++;
        }

        return $count;
    }

    /** What the person wrote or did in communities: posts and comments are blanked then removed; the rest is deleted. */
    private function eraseCommunityContent(User $user): int
    {
        $posts = PulsePost::query()->where('user_id', $user->id);
        $count = (clone $posts)->count();
        (clone $posts)->update(['title' => null, 'body' => 'This post was deleted.', 'status' => 'removed']);
        $posts->delete();

        PulseComment::query()->where('user_id', $user->id)->update(['body' => 'This comment was deleted.']);
        PulseComment::query()->where('user_id', $user->id)->delete();

        PulseReaction::query()->where('user_id', $user->id)->delete();
        PulseFollower::query()->where('follower_id', $user->id)->orWhere('following_id', $user->id)->delete();
        CommunityMembership::query()->where('user_id', $user->id)->delete();
        CommunityJoinRequest::query()->where('user_id', $user->id)->delete();
        PulsePollVote::query()->where('user_id', $user->id)->delete();
        PulseEventRsvp::query()->where('user_id', $user->id)->delete();
        PulseUserBadge::query()->where('user_id', $user->id)->delete();

        return $count;
    }

    /** Notifications other people hold ("Thandi sent an enquiry") keep their meaning without the name. */
    private function scrubName(User $user): void
    {
        $name = trim((string) $user->name);

        if (mb_strlen($name) < 2) {
            return;
        }

        $replacement = 'Someone who has left Pulse';
        $forms = array_unique([$name, trim((string) json_encode($name), '"'), trim((string) json_encode($name, JSON_UNESCAPED_UNICODE), '"')]);

        DB::table('notifications')->where('notifiable_id', '!=', $user->id)->where('type', PulseNotice::class)->where('data', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $forms[0] ?? $name).'%')
            ->orderBy('id')->chunkById(200, function ($rows) use ($forms, $replacement) {
                foreach ($rows as $row) {
                    $data = str_replace($forms, $replacement, (string) $row->data);
                    DB::table('notifications')->where('id', $row->id)->update(['data' => $data]);
                }
            }, 'id');
    }
}

<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseEntityInvitation;
use App\Models\PulseEntityMember;
use App\Models\User;
use App\Services\Pulse\Lines\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Team invitations. Adding someone by email used to say whether an address has an account and show the person's name.
 * Now an owner or admin invites an address, Pulse always answers with the same words (REPLY), and the person finds the
 * invitation on their own hub. Nothing in the reply, the error bag or the timing of the request depends on whether the
 * address belongs to anyone: the existence lookup runs every time, and the notification goes out after the response.
 *
 * The address is stored only as a keyed hash (HMAC with the app key, lowercased) and a masked hint for the inviter's
 * own list. An invitation lasts `pulse.invitations.ttl_days` (14). Only the person whose address it is can answer it.
 */
class InvitationService
{
    public const REPLY = 'If that person is on Pulse they will see your invitation.';

    public function __construct(private readonly Notifier $notifier) {}

    public static function hash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    /** "t***@example.test": enough for the inviter to recognise what they typed, not enough to recover it. */
    public static function hint(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', mb_strtolower(trim($email)), 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * @throws AuthorizationException when the inviter cannot hand out that role
     * @throws ValidationException when the role is not one, or the inviter has sent too many
     */
    public function invite(User $by, PulseEntity $entity, string $email, string $role): string
    {
        if (! in_array($role, PulseEntity::MEMBER_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Choose owner, admin, editor or responder.']);
        }

        Gate::forUser($by)->authorize('manageMembers', [$entity, $role]);

        $this->throttle($by, $entity);

        $email = mb_strtolower(trim($email));
        $hash = self::hash($email);

        // Always the same work: look the address up, whoever it belongs to.
        $person = User::query()->whereRaw('LOWER(email) = ?', [$email])->first(['id', 'name', 'email']);
        $alreadyOn = $person !== null && PulseEntityMember::query()->where('entity_id', $entity->id)->where('user_id', $person->id)->exists();

        $ttl = max(1, (int) config('pulse.invitations.ttl_days', 14));

        if (! $alreadyOn) {
            $invitation = PulseEntityInvitation::query()->pending()->where('entity_id', $entity->id)->where('email_hash', $hash)->first();

            $attrs = ['role' => $role, 'invited_by' => $by->id, 'expires_at' => now()->addDays($ttl), 'email_hint' => self::hint($email)];

            if ($invitation) {
                $invitation->forceFill($attrs)->save();
            } else {
                $invitation = PulseEntityInvitation::query()->create($attrs + ['entity_id' => $entity->id, 'email_hash' => $hash]);
            }

            $pageName = (string) $entity->name;

            // After the response, so the request takes the same time for a stranger's address and for a member's.
            defer(function () use ($email, $pageName, $invitation) {
                $recipient = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

                if ($recipient !== null) {
                    $this->notifier->notify($recipient, 'system', [
                        'type' => 'team.invitation',
                        'title' => $pageName.' invited you to join its team',
                        'body' => 'You were invited as '.$invitation->role.'. Say yes or no on your notifications page.',
                        'route' => ['name' => 'me.notifications'],
                        'coalesce_key' => 'team-invitation:'.$invitation->id,
                    ]);
                }
            });
        }

        Audit::record($by, 'entity.invite.send', $entity, ['role' => $role]);

        return self::REPLY;
    }

    /** What is waiting for this person, newest first. Matched on the keyed hash of their own address. */
    public function pendingFor(User $user): Collection
    {
        return PulseEntityInvitation::query()->pending()
            ->where('email_hash', self::hash((string) $user->email))
            ->with(['entity:id,name,slug,deleted_at', 'inviter:id,name'])
            ->latest('id')->get()
            ->filter(fn (PulseEntityInvitation $i) => $i->entity !== null)
            ->values();
    }

    /** Pending invitations to a page, for its owners and admins. */
    public function pendingForEntity(PulseEntity $entity): Collection
    {
        return PulseEntityInvitation::query()->pending()->where('entity_id', $entity->id)->with('inviter:id,name')->latest('id')->get();
    }

    /** @throws AuthorizationException when it is not this person's, or the inviter can no longer grant the role */
    public function accept(User $user, int $invitationId): PulseEntity
    {
        $invitation = $this->mine($user, $invitationId);
        $entity = $invitation->entity ?? throw new AuthorizationException;

        // The inviter must still be able to hand out that role. A removed or demoted inviter's invitation is void.
        $inviter = $invitation->invited_by ? User::query()->find($invitation->invited_by) : null;

        if ($inviter === null || ! Gate::forUser($inviter)->allows('manageMembers', [$entity, $invitation->role])) {
            $invitation->forceFill(['revoked_at' => now()])->save();

            throw new AuthorizationException;
        }

        DB::transaction(function () use ($invitation, $entity, $user) {
            $exists = PulseEntityMember::query()->where('entity_id', $entity->id)->where('user_id', $user->id)->exists();

            if (! $exists) {
                PulseEntityMember::query()->create(['entity_id' => $entity->id, 'user_id' => $user->id, 'role' => $invitation->role]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
            Audit::record($user, 'entity.invite.accept', $entity, ['role' => $invitation->role, 'already_member' => $exists]);
        });

        return $entity;
    }

    public function decline(User $user, int $invitationId): void
    {
        $invitation = $this->mine($user, $invitationId);
        $invitation->forceFill(['declined_at' => now()])->save();
        Audit::record($user, 'entity.invite.decline', $invitation->entity, ['role' => $invitation->role]);
    }

    /** The inviter, or anyone who could have sent it, takes it back. */
    public function revoke(User $by, PulseEntity $entity, int $invitationId): void
    {
        $invitation = PulseEntityInvitation::query()->pending()->where('entity_id', $entity->id)->whereKey($invitationId)->firstOrFail();

        Gate::forUser($by)->authorize('manageMembers', [$entity, $invitation->role]);

        $invitation->forceFill(['revoked_at' => now()])->save();
        Audit::record($by, 'entity.invite.revoke', $entity, ['role' => $invitation->role]);
    }

    private function mine(User $user, int $invitationId): PulseEntityInvitation
    {
        $invitation = PulseEntityInvitation::query()->pending()->with('entity')->whereKey($invitationId)->first();

        // The same refusal for "no such invitation" and "someone else's": a person cannot probe other people's.
        if ($invitation === null || ! hash_equals($invitation->email_hash, self::hash((string) $user->email))) {
            throw new AuthorizationException;
        }

        return $invitation;
    }

    private function throttle(User $by, PulseEntity $entity): void
    {
        $perPerson = 'pulse-invite:user:'.$by->id;
        $perPage = 'pulse-invite:page:'.$entity->id;

        if (RateLimiter::tooManyAttempts($perPerson, (int) config('pulse.invitations.per_person_hour', 20))
            || RateLimiter::tooManyAttempts($perPage, (int) config('pulse.invitations.per_page_day', 50))) {
            throw ValidationException::withMessages(['email' => 'That is a lot of invitations. Try again later.']);
        }

        RateLimiter::hit($perPerson, 3600);
        RateLimiter::hit($perPage, 86400);
    }
}

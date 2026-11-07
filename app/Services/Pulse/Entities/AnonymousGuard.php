<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseDoor;
use App\Models\PulseNeed;
use App\Models\PulseProfile;
use App\Models\User;
use App\Services\Pulse\Lines\Doors;
use Illuminate\Validation\ValidationException;

/**
 * An anonymous professional must not be linkable to their card through something they do under their name.
 *
 * A door carries the person's name and their profile place; a need carries their name, a place and topics. Either one
 * published beside an anonymous card (same skills, same region, close in time) would let an employer join the two. The
 * rule is the simple one: while someone is findable anonymously they do not open a door or publish a need as
 * themselves. Switching to anonymous closes the ones they have. Open mode, or off, lifts the rule at once.
 *
 * Not covered, on purpose: a community post or comment (public by the person's own act, a byline that never links to the
 * card and carries no place, see PulseProfile::publicName), and offers and roles (they belong to a business page, not
 * to the person).
 */
final class AnonymousGuard
{
    public const MESSAGE = 'Switch to open to do this under your name.';

    public static function isAnonymous(User $user): bool
    {
        return PulseProfile::query()->where('user_id', $user->id)->value('discoverability') === 'anonymous';
    }

    /** Closes what carries the person's name when they become anonymous. Returns how many doors and needs were closed. */
    public static function closeNamedSurfaces(User $user): int
    {
        $closed = 0;
        $doors = app(Doors::class);

        foreach (PulseDoor::query()->open()->where('owner_id', $user->id)->get() as $door) {
            $doors->close($user, $door);
            $closed++;
        }

        // Saved one by one so the listing observer takes each need's signal out of the index with it.
        foreach (PulseNeed::query()->where('user_id', $user->id)->where('status', 'open')->get() as $need) {
            $need->update(['status' => 'closed']);
            $closed++;
        }

        if ($closed > 0) {
            Audit::record($user, 'profile.anonymous_closed_named', null, ['closed' => $closed]);
        }

        return $closed;
    }

    public static function assertNeedAllowed(User $user): void
    {
        if (self::isAnonymous($user)) {
            throw ValidationException::withMessages(['title' => self::MESSAGE.' A need carries your name.']);
        }
    }
}

<?php

namespace App\Services\Pulse\Entities;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What Pulse really knows about a person's InfoDot companies: the teams the sign-in sync created or attached,
 * identified by teams.infodot_team_uuid. That is membership only. InfoDot shares no business verification
 * (R41_INTEGRATIONS.md), so nothing here ever means "verified".
 */
class InfoDotLink
{
    /** @return Collection<int, Team> companies (not personal teams) this person belongs to through InfoDot */
    public static function teams(User $user): Collection
    {
        return $user->allTeams()
            ->filter(fn (Team $team) => ! $team->personal_team && filled($team->infodot_team_uuid))
            ->values();
    }

    public static function has(User $user): bool
    {
        return self::teams($user)->isNotEmpty();
    }

    /** The company with this InfoDot uuid, if the person is in it. */
    public static function team(User $user, string $uuid): ?Team
    {
        return self::teams($user)->first(fn (Team $team) => $team->infodot_team_uuid === $uuid);
    }

    /** Owner or admin of the company here (InfoDot owner/admin map to the local owner or admin role). */
    public static function manages(User $user, Team $team): bool
    {
        return $team->user_id === $user->id || $user->hasTeamRole($team, 'admin');
    }
}

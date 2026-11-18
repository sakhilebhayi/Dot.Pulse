<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Jetstream;

/**
 * What the top bar's account menu holds, built in one place so the app layout and the public layout print the same thing.
 * The shape is <x-dot.account-menu>'s: links (with headings and one-button forms for the team switch), sign-out, theme.
 */
class ShellAccount
{
    /**
     * @param  list<array{name: string, slug: string, role: string}>  $businesses
     * @return array<string, mixed>
     */
    public static function for(User $user, array $businesses = []): array
    {
        $links = [
            ['label' => 'Profile and settings', 'href' => pulse_route('me.profile')],
            ['label' => 'Account and security', 'href' => pulse_route('me.account')],
        ];

        if ($businesses !== []) {
            $links[] = ['heading' => count($businesses) === 1 ? 'Your business' : 'Your businesses'];

            foreach ($businesses as $b) {
                $links[] = ['label' => 'Manage '.$b['name'], 'href' => pulse_route('entities.manage', ['entity' => $b['slug']])];
            }
        }

        if (Jetstream::hasTeamFeatures() && $user->currentTeam) {
            $links[] = ['heading' => 'Team'];
            $links[] = ['label' => 'Team settings', 'href' => route('teams.show', $user->currentTeam->id)];

            if ($user->can('create', Jetstream::newTeamModel())) {
                $links[] = ['label' => 'Create new team', 'href' => route('teams.create')];
            }

            if ($user->allTeams()->count() > 1) {
                $links[] = ['heading' => 'Switch team'];

                foreach ($user->allTeams() as $team) {
                    $links[] = [
                        'label' => $team->name,
                        'current' => $user->isCurrentTeam($team),
                        'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $team->id]],
                    ];
                }
            }
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            'team' => $user->currentTeam->name ?? 'Personal',
            'links' => $links,
            'signOut' => ['action' => route('logout'), 'label' => 'Log out'],
            'theme' => true,
        ];
    }

    /** The signed-out pair for the bar: only the routes that exist. */
    public static function guest(): array
    {
        return array_filter([
            'login' => Route::has('login') ? ['label' => 'Log in', 'href' => CentralSignIn::login()] : null,
        ]);
    }
}

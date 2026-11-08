<?php

namespace App\Livewire\Pulse\Floor\Support;

use App\Models\PulseEntity;
use App\Models\User;

/**
 * What to offer someone whose Floor has nothing for them yet. The empty state and the "caught up" end follow the
 * person's role: an employer is told to post a role, a seller to add an offer, a person looking for work to say what
 * they are open to, and nobody is pushed to "say what you need" in a mode where that is not their job.
 */
final class Persona
{
    /** @var array<int, array{employer: ?string, seller: ?string}> */
    private array $pages = [];

    /** @return array{employer: ?string, seller: ?string} slugs of the first live page the person can edit, by what it does */
    public function pages(User $user): array
    {
        return $this->pages[$user->id] ??= (function () use ($user) {
            $mine = PulseEntity::query()
                ->where('status', 'live')
                ->whereIn('id', fn ($q) => $q->select('entity_id')->from('pulse_entity_members')->where('user_id', $user->id)->whereIn('role', ['owner', 'admin', 'editor']))
                ->orderBy('id')
                ->get(['id', 'slug', 'is_seller', 'is_employer']);

            return [
                'employer' => $mine->firstWhere('is_employer', true)?->slug,
                'seller' => $mine->firstWhere('is_seller', true)?->slug,
            ];
        })();
    }

    /**
     * The actions for an empty Floor in a mode: the first is the one that moves things forward (yellow).
     *
     * @return list<array{label: string, href: string}>
     */
    public function emptyActions(User $user, string $mode): array
    {
        $pages = $this->pages($user);
        $roles = $pages['employer'] ? ['label' => 'Post a role', 'href' => pulse_route('entities.manage.roles', ['entity' => $pages['employer']])] : null;
        $offer = $pages['seller'] ? ['label' => 'Add an offer', 'href' => pulse_route('entities.manage.offers', ['entity' => $pages['seller']])] : null;
        $need = ['label' => 'Say what you need', 'href' => pulse_route('needs.create')];
        $open = ['label' => 'Say what you are open to', 'href' => pulse_route_first(['me.discover', 'profile.show'])];
        $follow = ['label' => 'Find businesses to follow', 'href' => pulse_route('search')];
        $place = ['label' => 'Set your place', 'href' => pulse_route_first(['me.discover', 'profile.show'])];
        $trade = ['label' => 'Look at Trade', 'href' => pulse_route('trade')];
        $interests = ['label' => 'Add your interests', 'href' => pulse_route_first(['me.profile', 'me', 'profile.show'])];

        $list = match ($mode) {
            'work' => $roles ? [$roles, $follow] : [$open, $follow, $trade],
            'trade' => $offer ? [$offer, $need] : [$need, $follow],
            'local' => [$place, $follow],
            'learn' => [$interests, $follow],
            default => array_values(array_filter([$roles ?? $offer, $roles || $offer ? null : $need, $roles || $offer ? $need : $open, $follow])),
        };

        return array_values(array_filter($list));
    }

    /** The one action offered when the round is finished. */
    public function endAction(User $user): array
    {
        $pages = $this->pages($user);

        return match (true) {
            $pages['employer'] !== null => ['label' => 'Post a role', 'href' => pulse_route('entities.manage.roles', ['entity' => $pages['employer']])],
            $pages['seller'] !== null => ['label' => 'Add an offer', 'href' => pulse_route('entities.manage.offers', ['entity' => $pages['seller']])],
            default => ['label' => 'Say what you need', 'href' => pulse_route('needs.create')],
        };
    }
}

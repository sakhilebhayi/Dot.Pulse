<?php

namespace App\Livewire\Pulse\Floor\Support;

use App\Livewire\Pulse\Me\Onboarding;
use App\Models\User;
use App\Services\Pulse\Discovery\ContextLoader;
use App\Services\Pulse\Discovery\Taxonomy;

/**
 * What the Floor says to someone as they arrive (from InfoDot, or on any later visit): who they are here as, the one
 * thing to do first, and, while the round is still empty, what the matcher holds about them.
 *
 * Nothing here is invented. The name and the company are the person's own records, the first action is the one
 * Persona already offers for their role, and every line of `knows()` is read from the matcher's own view of the
 * person (ContextLoader), so the page can never claim more than the matcher uses.
 */
final class Arrival
{
    public const LOGO = 'images/logo.png';

    public function __construct(private readonly Persona $persona, private readonly ContextLoader $contexts) {}

    /** The first word of the person's name. Empty when they have none. */
    public function firstName(User $user): string
    {
        return (string) (preg_split('/\s+/u', trim((string) $user->name), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '');
    }

    /**
     * The company the person is here for: their InfoDot company when they arrived with one, else the first business
     * page they run on Pulse. A personal team ("Naledi's Team") is not a company and is never shown as one.
     */
    public function company(User $user): ?string
    {
        $team = $user->currentTeam;

        if ($team !== null && ! $team->personal_team && trim((string) $team->name) !== '') {
            return (string) $team->name;
        }

        return pulse_my_businesses()[0]['name'] ?? null;
    }

    /**
     * The one thing to do first: put what you want on the floor. It follows the person's role, as the rest of the
     * Floor does (an employer posts a role, a seller adds an offer, anyone else says what they need).
     *
     * @return array{label: string, href: string}
     */
    public function action(User $user): array
    {
        return $this->persona->endAction($user);
    }

    /** @return array{home: string, platforms: string} the way back to InfoDot, from the one setting that names its host */
    public function infodot(): array
    {
        $base = rtrim(pulse_infodot_url(), '/');

        return ['home' => $base.'/home', 'platforms' => $base.'/platforms'];
    }

    /**
     * What the matcher holds about the person, one line per input, each with the place to change it. A line with
     * nothing behind it says so. Counts are counts of the person's own records and of nothing else.
     *
     * @return list<array{label: string, value: ?string, missing: string, action: string, href: string}>
     */
    public function knows(User $user): array
    {
        $ctx = $this->contexts->load([$user->id])[$user->id];
        $pages = $this->persona->pages($user);

        $labels = fn (array $slugs): ?string => $slugs === [] ? null : $this->sentence(array_map(fn ($s) => Taxonomy::label((string) $s), $slugs));
        $openTo = array_values(array_filter(array_map(fn ($key) => Onboarding::OPEN_TO_LABELS[$key] ?? null, $ctx->openTo)));
        $needs = count($ctx->needs);
        $follows = count($ctx->followedEntityIds);

        return [
            [
                'label' => 'What you do',
                'value' => $labels($ctx->skills),
                'missing' => 'No skills on your profile yet.',
                'action' => 'Add your skills',
                'href' => pulse_route_first(['me.profile', 'me', 'profile.show']),
            ],
            [
                'label' => 'What you are open to',
                'value' => $openTo === [] ? null : $this->sentence($openTo),
                'missing' => 'Nothing said yet.',
                'action' => 'Say what you are open to',
                'href' => pulse_route_first(['me.discover', 'profile.show']),
            ],
            [
                'label' => 'What you need',
                'value' => $needs === 0 ? null : $needs.' open '.($needs === 1 ? 'need' : 'needs'),
                'missing' => 'No open need.',
                'action' => 'Say what you need',
                'href' => pulse_route('needs.create'),
            ],
            [
                'label' => 'What you offer',
                'value' => $labels($ctx->offerTopics),
                'missing' => 'No live offer.',
                'action' => $pages['seller'] ? 'Add an offer' : 'Set up a business page',
                'href' => $pages['seller'] ? pulse_route('entities.manage.offers', ['entity' => $pages['seller']]) : pulse_route('entities.create'),
            ],
            [
                'label' => 'Who you follow',
                'value' => $follows === 0 ? null : $follows.' '.($follows === 1 ? 'business' : 'businesses'),
                'missing' => 'No business followed yet.',
                'action' => 'Find businesses to follow',
                'href' => pulse_route('search'),
            ],
            [
                'label' => 'Where you are',
                'value' => $ctx->place?->name,
                'missing' => 'No place set.',
                'action' => 'Set your place',
                'href' => pulse_route_first(['me.discover', 'profile.show']),
            ],
        ];
    }

    /**
     * Up to four names in a row, then "and N more".
     *
     * @param  list<string>  $names
     */
    private function sentence(array $names): string
    {
        $names = array_values(array_unique($names));
        $shown = array_slice($names, 0, 4);
        $rest = count($names) - count($shown);

        return implode(', ', $shown).($rest > 0 ? ' and '.$rest.' more' : '');
    }
}

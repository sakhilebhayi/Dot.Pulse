<?php

namespace Tests\Concerns;

use App\Models\Community;
use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\User;

/**
 * Small, explicit fixture builders. Content strings that a test asserts on
 * must be long and distinctive: assertSee/assertDontSee against a whole
 * rendered page with short or numeric strings collides with random data.
 */
trait BuildsPulseFixtures
{
    protected function pulseUser(?string $role = null, bool $withTeam = true): User
    {
        $factory = User::factory();
        $user = ($withTeam ? $factory->withPersonalTeam() : $factory)->create();

        if ($role !== null) {
            PulseProfile::create(['user_id' => $user->id, 'role' => $role]);
        }

        return $user;
    }

    protected function platformModerator(): User
    {
        return $this->pulseUser('moderator');
    }

    protected function publicCommunity(array $attrs = []): Community
    {
        return Community::factory()->create($attrs);
    }

    protected function privateCommunity(array $attrs = []): Community
    {
        return Community::factory()->private()->create($attrs);
    }

    protected function joined(Community $community, ?User $user = null, string $role = 'member'): User
    {
        $user ??= $this->pulseUser();
        $community->addMember($user, $role);

        return $user;
    }

    protected function postIn(?Community $community, array $attrs = []): PulsePost
    {
        return PulsePost::factory()->create(array_merge([
            'community_id' => $community?->id,
        ], $attrs));
    }

    protected function commentOn(PulsePost $post, array $attrs = []): PulseComment
    {
        return PulseComment::factory()->create(array_merge([
            'pulse_post_id' => $post->id,
        ], $attrs));
    }
}

<?php

namespace Tests\Feature\Entities;

use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use App\Services\Pulse\Lines\Access;
use Illuminate\Support\Facades\Gate;

class PolicyTest extends EntitiesTestCase
{
    public function test_entity_abilities_by_role(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $people = [
            'owner' => $owner,
            'admin' => $this->member($entity, 'admin'),
            'editor' => $this->member($entity, 'editor'),
            'responder' => $this->member($entity, 'responder'),
            'stranger' => $this->person(),
            'moderator' => $this->moderator(),
        ];

        $matrix = [
            //            update manageListings manageMembers respond archive moderate (respond = Access::RESPONDER_ROLES)
            'owner' => [true, true, true, true, true, false],
            'admin' => [true, true, true, true, false, false],
            'editor' => [false, true, false, false, false, false], // editors edit listings, they do not read lines
            'responder' => [false, false, false, true, false, false],
            'stranger' => [false, false, false, false, false, false],
            'moderator' => [false, false, false, false, false, true],
        ];

        foreach ($matrix as $who => $expected) {
            foreach (['update', 'manageListings', 'manageMembers', 'respond', 'archive', 'moderate'] as $i => $ability) {
                $this->assertSame($expected[$i], Gate::forUser($people[$who])->allows($ability, $entity), "$who / $ability");
            }
        }
    }

    public function test_admins_manage_lower_roles_only(): void
    {
        $entity = $this->page();
        $admin = $this->member($entity, 'admin');
        $owner = $entity->owners()->first()->user;

        foreach (['editor' => true, 'responder' => true, 'admin' => false, 'owner' => false] as $role => $allowed) {
            $this->assertSame($allowed, Gate::forUser($admin)->allows('manageMembers', [$entity, $role]), $role);
            $this->assertTrue(Gate::forUser($owner)->allows('manageMembers', [$entity, $role]));
        }
    }

    public function test_viewing_a_page(): void
    {
        $live = $this->page();
        $pending = $this->page(null, ['moderation' => 'pending']);
        $draft = $this->page(null, ['status' => 'draft']);
        $suspended = $this->page(null, ['status' => 'suspended']);
        $stranger = $this->person();
        $moderator = $this->moderator();

        $this->assertTrue(Gate::forUser(null)->allows('view', $live));
        $this->assertTrue(Gate::forUser($stranger)->allows('view', $live));

        foreach ([$pending, $draft, $suspended] as $hidden) {
            $this->assertFalse(Gate::forUser(null)->allows('view', $hidden));
            $this->assertFalse(Gate::forUser($stranger)->allows('view', $hidden));
            $this->assertTrue(Gate::forUser($hidden->owners()->first()->user)->allows('view', $hidden));
            $this->assertTrue(Gate::forUser($moderator)->allows('view', $hidden));
        }

        $live->delete();
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $live));
    }

    public function test_offer_and_role_abilities(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $editor = $this->member($entity, 'editor');
        $responder = $this->member($entity, 'responder');
        $stranger = $this->person();
        $moderator = $this->moderator();

        foreach ([PulseOffer::factory()->create(['entity_id' => $entity->id]), PulseRole::factory()->create(['entity_id' => $entity->id])] as $listing) {
            foreach (['update', 'delete', 'publish'] as $ability) {
                $this->assertTrue(Gate::forUser($owner)->allows($ability, $listing));
                $this->assertTrue(Gate::forUser($editor)->allows($ability, $listing));
                $this->assertFalse(Gate::forUser($responder)->allows($ability, $listing), $ability);
                $this->assertFalse(Gate::forUser($stranger)->allows($ability, $listing), $ability);
                $this->assertFalse(Gate::forUser($moderator)->allows($ability, $listing), $ability);
            }

            $this->assertTrue(Gate::forUser($moderator)->allows('moderate', $listing));
            $this->assertFalse(Gate::forUser($owner)->allows('moderate', $listing));
        }
    }

    public function test_viewing_listings(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $stranger = $this->person();
        $offer = PulseOffer::factory()->create(['entity_id' => $entity->id]);
        $held = PulseOffer::factory()->pendingModeration()->create(['entity_id' => $entity->id]);
        $draft = PulseOffer::factory()->draft()->create(['entity_id' => $entity->id]);

        $this->assertTrue(Gate::forUser(null)->allows('view', $offer));
        $this->assertTrue(Gate::forUser($stranger)->allows('view', $offer));

        foreach ([$held, $draft] as $hidden) {
            $this->assertFalse(Gate::forUser(null)->allows('view', $hidden));
            $this->assertFalse(Gate::forUser($stranger)->allows('view', $hidden));
            $this->assertFalse(Gate::forUser($this->member($entity, 'responder'))->allows('view', $hidden));
            $this->assertTrue(Gate::forUser($owner)->allows('view', $hidden));
            $this->assertTrue(Gate::forUser($this->moderator())->allows('view', $hidden));
        }

        $entity->forceFill(['status' => 'draft'])->save();
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $offer->fresh()), 'an offer is hidden with its page');
    }

    public function test_needs_belong_to_their_owner(): void
    {
        $owner = $this->person();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id]);
        $matched = PulseNeed::factory()->create(['user_id' => $owner->id, 'visibility' => 'matched']);
        $held = PulseNeed::factory()->pendingModeration()->create(['user_id' => $owner->id]);
        $stranger = $this->person();

        $this->assertFalse(Gate::forUser(null)->allows('view', $need));
        $this->assertTrue(Gate::forUser($stranger)->allows('view', $need));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $matched));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $held));

        foreach ([$need, $matched, $held] as $n) {
            $this->assertTrue(Gate::forUser($owner)->allows('view', $n));
            $this->assertTrue(Gate::forUser($this->moderator())->allows('view', $n));
            $this->assertTrue(Gate::forUser($owner)->allows('update', $n));
            $this->assertFalse(Gate::forUser($stranger)->allows('update', $n));
            $this->assertFalse(Gate::forUser($stranger)->allows('delete', $n));
            $this->assertFalse(Gate::forUser($this->moderator())->allows('update', $n));
        }
    }

    public function test_scopes_agree_with_the_policies(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $stranger = $this->person();
        $live = PulseOffer::factory()->create(['entity_id' => $entity->id]);
        $held = PulseOffer::factory()->pendingModeration()->create(['entity_id' => $entity->id]);

        $this->assertSame([$live->id], PulseOffer::forViewer(null)->pluck('id')->all());
        $this->assertSame([$live->id], PulseOffer::forViewer($stranger)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$live->id, $held->id], PulseOffer::forViewer($owner)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$live->id, $held->id], PulseOffer::forViewer($this->moderator())->pluck('id')->all());

        $hiddenPage = $this->page(null, ['moderation' => 'pending']);
        $this->assertNotContains($hiddenPage->id, PulseEntity::forViewer($stranger)->pluck('id')->all());
        $this->assertContains($hiddenPage->id, PulseEntity::forViewer($hiddenPage->owners()->first()->user)->pluck('id')->all());
        $this->assertSame(1, PulseEntity::live()->where('id', $entity->id)->count());

        $mine = PulseNeed::factory()->create(['user_id' => $owner->id, 'visibility' => 'matched']);
        $this->assertNotContains($mine->id, PulseNeed::forViewer($stranger)->pluck('id')->all());
        $this->assertContains($mine->id, PulseNeed::forViewer($owner)->pluck('id')->all());
    }

    public function test_the_respond_policy_and_the_lines_access_agree_for_every_role(): void
    {
        $entity = $this->page();

        foreach (['owner', 'admin', 'editor', 'responder'] as $role) {
            $user = $role === 'owner' ? $entity->members()->where('role', 'owner')->first()->user : $this->member($entity, $role);

            $this->assertSame(
                Access::canRespondFor($user, $entity),
                Gate::forUser($user)->allows('respond', $entity),
                $role,
            );
            $this->assertSame($role !== 'editor', Gate::forUser($user)->allows('respond', $entity), $role);
        }
    }
}

<?php

namespace Tests\Feature\Business;

use App\Livewire\Pulse\Business\TeamManager;
use App\Livewire\Pulse\Me\Invitations;
use App\Models\PulseAuditEntry;
use App\Models\PulseEntityInvitation;
use App\Models\PulseEntityMember;
use App\Services\Pulse\Entities\InvitationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

class TeamInvitationTest extends BusinessTestCase
{
    private function send($owner, $page, string $email, string $role = 'editor')
    {
        return Livewire::actingAs($owner)->test(TeamManager::class, ['entity' => $page])->set('email', $email)->set('role', $role)->call('add');
    }

    public function test_the_reply_is_identical_for_a_member_a_stranger_and_an_unknown_address_and_no_name_is_shown(): void
    {
        $this->withoutDefer();
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $known = $this->person(['name' => 'Zodwa Ndlovu-Secret', 'email' => 'zodwa@example.test']);
        $member = $this->member($page, 'editor');

        $replies = [];

        foreach (['zodwa@example.test', $member->email, 'ghost@example.test'] as $email) {
            $c = $this->send($owner, $page, $email)->assertHasNoErrors();
            $html = $c->html();
            $this->assertStringContainsString(InvitationService::REPLY, $html);
            $this->assertStringNotContainsString('Zodwa Ndlovu-Secret', $html);
            $this->assertStringNotContainsString('Nobody on Pulse', $html);
            $replies[] = $c->get('notice');
        }

        $this->assertCount(1, array_unique($replies), 'one answer for every address');
        $this->assertSame(2, PulseEntityInvitation::count(), 'a current member gets no invitation, silently');
        $this->assertNull($page->memberRole($known), 'inviting is not adding');
    }

    public function test_the_address_is_stored_only_as_a_keyed_hash_and_a_masked_hint(): void
    {
        $page = $this->page();
        $this->send($page->members()->first()->user, $page, 'Thandi.Mokoena@Example.test', 'responder');

        $row = DB::table('pulse_entity_invitations')->first();
        $this->assertSame(InvitationService::hash('thandi.mokoena@example.test'), $row->email_hash);
        $this->assertSame(64, strlen($row->email_hash));
        $this->assertSame('t***@example.test', $row->email_hint);
        $this->assertStringNotContainsStringIgnoringCase('mokoena', json_encode((array) $row));
        $this->assertEqualsWithDelta(14 * 86400, now()->diffInSeconds($row->expires_at, false), 5);
        $this->assertNull($row->accepted_at);
    }

    public function test_the_person_sees_a_notification_and_the_invitation_and_accepts_or_declines(): void
    {
        $this->withoutDefer();
        $page = $this->page(null, ['name' => 'Moyo Books']);
        $owner = $page->members()->first()->user;
        $a = $this->person(['email' => 'a@example.test']);
        $b = $this->person(['email' => 'b@example.test']);

        $this->send($owner, $page, 'a@example.test');
        $this->send($owner, $page, 'b@example.test', 'responder');

        $this->assertSame(1, $a->notifications()->count(), 'a notification for a person who is on Pulse');
        $this->assertStringContainsString('Moyo Books', json_encode($a->notifications()->first()->data));

        $this->actingAs($a)->get(route('me.notifications'))->assertOk()->assertSee('Moyo Books');

        $ia = PulseEntityInvitation::where('email_hash', InvitationService::hash('a@example.test'))->first();
        $ib = PulseEntityInvitation::where('email_hash', InvitationService::hash('b@example.test'))->first();

        Livewire::actingAs($a)->test(Invitations::class)->assertSee('Moyo Books')->call('accept', $ia->id);
        $this->assertSame('editor', $page->memberRole($a));
        $this->assertNotNull($ia->fresh()->accepted_at);

        Livewire::actingAs($b)->test(Invitations::class)->call('decline', $ib->id);
        $this->assertNull($page->memberRole($b));
        $this->assertNotNull($ib->fresh()->declined_at);
        Livewire::actingAs($b)->test(Invitations::class)->assertDontSee('Moyo Books');
    }

    public function test_nobody_else_can_answer_an_invitation_and_a_stale_one_does_nothing(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $target = $this->person(['email' => 'target@example.test']);
        $thief = $this->person(['email' => 'thief@example.test']);
        $this->send($owner, $page, 'target@example.test', 'admin');
        $invitation = PulseEntityInvitation::first();

        Livewire::actingAs($thief)->test(Invitations::class)->call('accept', $invitation->id)->assertSee('no longer open');
        Livewire::actingAs($thief)->test(Invitations::class)->call('accept', 99999)->assertSee('no longer open');
        $this->assertNull($page->memberRole($thief));
        $this->assertNull($invitation->fresh()->accepted_at);

        $this->travel(15)->days();
        Livewire::actingAs($target)->test(Invitations::class)->assertDontSee($page->name)->call('accept', $invitation->id)->assertSee('no longer open');
        $this->assertNull($page->memberRole($target));
    }

    public function test_an_invitation_from_someone_who_lost_the_power_is_void(): void
    {
        $page = $this->page();
        $admin = $this->member($page, 'admin');
        $target = $this->person(['email' => 'target@example.test']);
        $this->send($admin, $page, 'target@example.test', 'editor');
        $invitation = PulseEntityInvitation::first();

        PulseEntityMember::where('user_id', $admin->id)->delete();

        Livewire::actingAs($target)->test(Invitations::class)->call('accept', $invitation->id)->assertSee('no longer open');
        $this->assertNull($page->memberRole($target));
    }

    public function test_the_owner_can_take_an_invitation_back_and_an_admin_cannot_revoke_an_admin_one(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $admin = $this->member($page, 'admin');
        $target = $this->person(['email' => 'target@example.test']);
        $this->send($owner, $page, 'target@example.test', 'admin');
        $invitation = PulseEntityInvitation::first();

        Livewire::actingAs($admin)->test(TeamManager::class, ['entity' => $page])->call('revoke', $invitation->id)->assertSee('cannot take that invitation back');
        $this->assertNull($invitation->fresh()->revoked_at);

        Livewire::actingAs($owner)->test(TeamManager::class, ['entity' => $page])->assertSee('t***@example.test')->call('revoke', $invitation->id)->assertSee('Invitation taken back');
        $this->assertNotNull($invitation->fresh()->revoked_at);

        Livewire::actingAs($target)->test(Invitations::class)->call('accept', $invitation->id)->assertSee('no longer open');
        $this->assertNull($page->memberRole($target));
    }

    public function test_an_admin_cannot_invite_an_owner(): void
    {
        $page = $this->page();
        $admin = $this->member($page, 'admin');

        $this->send($admin, $page, 'someone@example.test', 'owner')->assertHasErrors(['role']);
        $this->assertSame(0, PulseEntityInvitation::count());
    }

    public function test_sending_is_rate_limited_and_audited_without_the_address(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;
        RateLimiter::clear('pulse-invite:user:'.$owner->id);
        config(['pulse.invitations.per_person_hour' => 3]);

        foreach (range(1, 3) as $i) {
            $this->send($owner, $page, "p{$i}@example.test")->assertHasNoErrors();
        }

        $this->send($owner, $page, 'p4@example.test')->assertHasErrors(['email']);
        $this->assertSame(3, PulseEntityInvitation::count());

        $rows = PulseAuditEntry::where('action', 'entity.invite.send')->get();
        $this->assertCount(3, $rows);
        $this->assertStringNotContainsString('example.test', json_encode($rows->toArray()));
    }

    public function test_a_second_invitation_to_the_same_address_renews_the_first(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        $this->send($owner, $page, 'same@example.test', 'editor');
        $this->travel(5)->days();
        $this->send($owner, $page, 'SAME@example.test', 'responder');

        $this->assertSame(1, PulseEntityInvitation::count());
        $row = PulseEntityInvitation::first();
        $this->assertSame('responder', $row->role);
        $this->assertTrue($row->expires_at->isAfter(now()->addDays(13)));
    }
}

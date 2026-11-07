<?php

namespace Tests\Feature\Business;

use App\Livewire\Pulse\Business\TeamManager;
use App\Livewire\Pulse\Business\VerifyPanel;
use Livewire\Livewire;

class TeamAndVerifyTest extends BusinessTestCase
{
    public function test_only_owners_and_admins_open_the_team_tab(): void
    {
        $page = $this->page();

        Livewire::actingAs($page->members()->first()->user)->test(TeamManager::class, ['entity' => $page])->assertOk();
        Livewire::actingAs($this->member($page, 'admin'))->test(TeamManager::class, ['entity' => $page])->assertOk();
        Livewire::actingAs($this->member($page, 'editor'))->test(TeamManager::class, ['entity' => $page])->assertForbidden();
        Livewire::actingAs($this->member($page, 'responder'))->test(TeamManager::class, ['entity' => $page])->assertForbidden();
        Livewire::actingAs($this->person())->test(TeamManager::class, ['entity' => $page])->assertForbidden();
    }

    public function test_an_owner_adds_a_member_by_email_and_changes_and_removes_them(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $newcomer = $this->person(['email' => 'Thandi@Example.test']);

        $c = Livewire::actingAs($owner)->test(TeamManager::class, ['entity' => $page])
            ->set('email', 'thandi@example.test')->set('role', 'editor')
            ->call('add')->assertHasNoErrors()->assertSee('is now editor');

        $this->assertSame('editor', $page->memberRole($newcomer));

        $c->call('changeRole', $newcomer->id, 'responder');
        $this->assertSame('responder', $page->memberRole($newcomer));

        $c->call('remove', $newcomer->id)->assertSee('was removed');
        $this->assertNull($page->memberRole($newcomer));
    }

    public function test_an_unknown_email_is_explained_not_created(): void
    {
        $page = $this->page();

        Livewire::actingAs($page->members()->first()->user)->test(TeamManager::class, ['entity' => $page])
            ->set('email', 'nobody@example.test')->call('add')
            ->assertHasErrors(['email'])->assertSee('sign in to Pulse through InfoDot once');

        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.test']);
    }

    public function test_the_last_owner_is_never_removed(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        Livewire::actingAs($owner)->test(TeamManager::class, ['entity' => $page])
            ->call('remove', $owner->id)->assertSee('always needs an owner');

        $this->assertSame('owner', $page->memberRole($owner));

        Livewire::actingAs($owner)->test(TeamManager::class, ['entity' => $page])
            ->call('changeRole', $owner->id, 'editor')->assertSee('always needs an owner');
        $this->assertSame('owner', $page->memberRole($owner));
    }

    public function test_an_admin_cannot_grant_owner_or_touch_an_owner(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $admin = $this->member($page, 'admin');
        $other = $this->person();

        $c = Livewire::actingAs($admin)->test(TeamManager::class, ['entity' => $page]);
        $this->assertArrayNotHasKey('owner', $c->get('grantable'));

        $c->set('email', $other->email)->set('role', 'owner')->call('add')->assertHasErrors(['role']);
        $this->assertNull($page->memberRole($other));

        $c->call('remove', $owner->id)->assertSee('cannot remove');
        $this->assertSame('owner', $page->memberRole($owner));
    }

    public function test_a_member_can_leave_but_a_stranger_cannot_remove_anyone(): void
    {
        $page = $this->page();
        $admin = $this->member($page, 'admin');
        $editor = $this->member($page, 'editor');

        Livewire::actingAs($admin)->test(TeamManager::class, ['entity' => $page])->call('remove', $admin->id)->assertRedirect();
        $this->assertNull($page->memberRole($admin));

        $stranger = $this->person();
        Livewire::actingAs($stranger)->test(TeamManager::class, ['entity' => $page])->assertForbidden();
        $this->assertSame('editor', $page->memberRole($editor));
    }

    public function test_the_verify_tab_is_for_owners_and_admins(): void
    {
        $page = $this->page();

        Livewire::actingAs($page->members()->first()->user)->test(VerifyPanel::class, ['entity' => $page])->assertOk();
        Livewire::actingAs($this->member($page, 'admin'))->test(VerifyPanel::class, ['entity' => $page])->assertOk();
        Livewire::actingAs($this->member($page, 'editor'))->test(VerifyPanel::class, ['entity' => $page])->assertForbidden();
        Livewire::actingAs($this->person())->test(VerifyPanel::class, ['entity' => $page])->assertForbidden();
    }

    public function test_the_ladder_is_honest_and_the_page_never_says_verified_business(): void
    {
        $page = $this->page(null, ['website' => 'https://moyobooks.test']);
        $owner = $page->members()->first()->user;

        $c = Livewire::actingAs($owner)->test(VerifyPanel::class, ['entity' => $page])
            ->assertSee('Claimed by them, not checked')
            ->assertSee('does not mean InfoDot has checked that the business exists');

        $this->assertStringNotContainsStringIgnoringCase('verified business', $c->html());
    }

    public function test_claim_registration_and_link_unlink_a_company(): void
    {
        $owner = $this->person();
        $team = $this->company($owner, 'Acme Holdings');
        $page = $this->page($owner->fresh());

        $c = Livewire::actingAs($owner->fresh())->test(VerifyPanel::class, ['entity' => $page])
            ->set('registration_number', '2019/123456/07')->call('claim')->assertHasNoErrors()->assertSee('Saved as a claim');
        $this->assertSame('2019/123456/07', $page->fresh()->registration_number);

        $c->set('registration_number', '!!')->call('claim')->assertHasErrors(['registration_number']);

        $c->set('team', $team->infodot_team_uuid)->call('link')->assertHasNoErrors()->assertSee('Linked');
        $this->assertSame($team->infodot_team_uuid, $page->fresh()->infodot_business_ref);
        $this->assertSame('infodot_member', $page->fresh()->verification_status);

        $c->call('unlink')->assertSee('Unlinked');
        $this->assertNull($page->fresh()->infodot_business_ref);
    }

    public function test_an_admin_cannot_link_a_company_and_a_foreign_company_is_refused(): void
    {
        $owner = $this->person();
        $page = $this->page($owner);
        $admin = $this->member($page, 'admin');
        $stranger = $this->person();
        $theirs = $this->company($stranger, 'Not Yours');

        Livewire::actingAs($owner)->test(VerifyPanel::class, ['entity' => $page])
            ->set('team', $theirs->infodot_team_uuid)->call('link')->assertHasErrors(['team']);
        $this->assertNull($page->fresh()->infodot_business_ref);

        $team = $this->company($admin, 'Admin Co');
        Livewire::actingAs($admin->fresh())->test(VerifyPanel::class, ['entity' => $page])
            ->assertSee('Only an owner can link')
            ->set('team', $team->infodot_team_uuid)->call('link')->assertHasErrors(['team']);
        $this->assertNull($page->fresh()->infodot_business_ref);
    }
}

<?php

namespace Tests\Feature\Business;

use App\Livewire\Pulse\Business\RoleManager;
use App\Models\PulseRole;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

class RoleManagerTest extends BusinessTestCase
{
    public function test_who_can_open_the_roles_tab(): void
    {
        $page = $this->page();

        Livewire::actingAs($page->members()->first()->user)->test(RoleManager::class, ['entity' => $page])->assertOk();
        Livewire::actingAs($this->member($page, 'editor'))->test(RoleManager::class, ['entity' => $page])->assertOk();
        Livewire::actingAs($this->member($page, 'responder'))->test(RoleManager::class, ['entity' => $page])->assertForbidden();
        Livewire::actingAs($this->person())->test(RoleManager::class, ['entity' => $page])->assertForbidden();
    }

    public function test_create_publish_close_fill_and_delete(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        $c = Livewire::actingAs($owner)->test(RoleManager::class, ['entity' => $page])
            ->call('newRole')
            ->set('title', 'Junior bookkeeper')->set('summary', 'Help clients keep clean books.')
            ->set('skills_text', 'bookkeeping, excel')->set('pay_text', 'R15 000 a month')
            ->call('savePublish')->assertHasNoErrors()->assertSee('Published');

        $role = PulseRole::where('title', 'Junior bookkeeper')->firstOrFail();
        $this->assertSame('live', $role->status);
        $this->assertSame(['bookkeeping', 'excel'], $role->skills);

        $c->call('pause', $role->id);
        $this->assertSame('paused', $role->fresh()->status);
        $c->call('publish', $role->id);
        $c->call('close', $role->id)->assertSee('Closed');
        $this->assertSame('closed', $role->fresh()->status);

        $second = PulseRole::factory()->create(['entity_id' => $page->id, 'created_by' => $owner->id]);
        $c->call('markFilled', $second->id)->assertSee('filled');
        $this->assertSame('filled', $second->fresh()->status);

        $c->call('delete', $second->id);
        $this->assertSoftDeleted('pulse_roles', ['id' => $second->id]);
    }

    public function test_an_external_apply_role_needs_an_address(): void
    {
        $page = $this->page();

        Livewire::actingAs($page->members()->first()->user)->test(RoleManager::class, ['entity' => $page])
            ->call('newRole')->set('title', 'Driver')->set('summary', 'Drive things.')->set('apply_mode', 'external_url')->set('apply_url', '')
            ->call('save')->assertHasErrors(['apply_url']);
    }

    public function test_a_page_that_does_not_hire_is_pointed_to_the_page_tab(): void
    {
        $page = $this->page(null, ['is_employer' => false]);

        Livewire::actingAs($page->members()->first()->user)->test(RoleManager::class, ['entity' => $page])->assertSee('does not hire yet');
    }

    public function test_the_hiring_signal_is_for_owners_and_admins_and_explains_the_cohort_rule(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        Livewire::actingAs($owner)->test(RoleManager::class, ['entity' => $page])
            ->assertSee('at least 5')->assertSee('anonymous')
            ->set('hiring_signal', 'building')->set('hiring_skills_text', 'bookkeeping, payroll')
            ->call('saveSignal')->assertHasNoErrors()->assertSee('building a team');

        $fresh = $page->fresh();
        $this->assertSame('building', $fresh->hiring_signal);
        $this->assertSame(['bookkeeping', 'payroll'], $fresh->hiring_skills);

        // an editor can run roles, not the signal
        Livewire::actingAs($this->member($page, 'editor'))->test(RoleManager::class, ['entity' => $page])
            ->assertSee('Only an owner or an admin can change the signal')
            ->set('hiring_signal', 'active')->call('saveSignal')->assertHasErrors(['hiring_signal']);
        $this->assertSame('building', $page->fresh()->hiring_signal);
    }

    public function test_a_role_of_another_page_cannot_be_reached_by_id(): void
    {
        $mine = $this->page();
        $theirs = $this->page();
        $role = PulseRole::factory()->create(['entity_id' => $theirs->id, 'created_by' => $theirs->created_by]);

        try {
            Livewire::actingAs($mine->members()->first()->user)->test(RoleManager::class, ['entity' => $mine])->call('close', $role->id);
            $this->fail('A role of another page must not be found.');
        } catch (ModelNotFoundException) {
            $this->assertSame('live', $role->fresh()->status);
        }
    }

    public function test_a_held_role_says_it_is_waiting_for_a_human(): void
    {
        $owner = $this->person();
        $page = $this->page($owner);

        Livewire::actingAs($owner)->test(RoleManager::class, ['entity' => $page])
            ->call('newRole')->set('title', 'Waits for a look')->set('summary', 'Summary here.')
            ->call('savePublish')->assertSee('Waiting for a human');
    }
}

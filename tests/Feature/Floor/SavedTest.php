<?php

namespace Tests\Feature\Floor;

use App\Livewire\Pulse\Floor\Saved;
use App\Services\Pulse\Discovery\Floor;
use Livewire\Livewire;

class SavedTest extends FloorTestCase
{
    private function saved(array $query = [])
    {
        return Livewire::actingAs($this->me)->withQueryParams($query)->test(Saved::class);
    }

    public function test_nothing_saved_is_an_honest_empty_state(): void
    {
        $this->saved()->assertSee('Nothing saved yet.')->assertSee('Back to the floor');
    }

    public function test_saved_beats_are_listed_newest_save_first_and_say_saved(): void
    {
        $a = $this->matched(['title' => 'First saved']);
        $b = $this->matched(['title' => 'Second saved']);
        app(Floor::class)->saveForLater($this->me, $a);
        app(Floor::class)->saveForLater($this->me, $b);

        $c = $this->saved()->assertSee('First saved')->assertSee('Second saved');

        $this->assertSame(['Second saved', 'First saved'], array_column($c->get('beats'), 'title'));
        $this->assertTrue($c->get('beats')[0]['saved']);
    }

    public function test_unsave_takes_it_off_the_list_and_off_the_saved_table(): void
    {
        $a = $this->matched(['title' => 'Change of mind']);
        app(Floor::class)->saveForLater($this->me, $a);

        $this->saved()->call('unsave', $a->id)->assertDontSee('Change of mind')->assertSee('Nothing saved yet.')->assertDispatched('pulse-saved-changed');

        $this->assertDatabaseCount('pulse_saves', 0);
    }

    public function test_the_kind_filter_narrows_the_list_and_only_offers_kinds_that_were_saved(): void
    {
        [$offer] = $this->offerFor('A saved offer');
        $ask = $this->matched(['title' => 'A saved ask', 'kind' => 'ask']);
        $event = $this->matched(['title' => 'A saved event', 'kind' => 'event']);
        foreach ([$offer, $ask, $event] as $s) {
            app(Floor::class)->saveForLater($this->me, $s);
        }

        $this->saved(['kind' => 'ask'])
            ->assertSee('A saved ask')
            ->assertDontSee('A saved offer')
            ->assertDontSee('A saved event')
            ->assertSee('Offers')
            ->assertSee('Events')
            ->assertDontSee('Roles');

        $this->saved(['kind' => 'nonsense'])->assertSet('kind', '')->assertSee('A saved offer');
    }

    public function test_things_that_have_gone_since_they_were_saved_are_left_out(): void
    {
        $gone = $this->matched(['title' => 'Gone later']);
        $blocked = $this->person();
        $theirs = $this->matched(['title' => 'Blocked later', 'owner_id' => $blocked->id]);
        $kept = $this->matched(['title' => 'Still here']);
        foreach ([$gone, $theirs, $kept] as $s) {
            app(Floor::class)->saveForLater($this->me, $s);
        }
        $gone->forceFill(['status' => 'removed'])->save();
        $this->dBlock($this->me, $blocked);

        $this->saved()->assertSee('Still here')->assertDontSee('Gone later')->assertDontSee('Blocked later');
    }

    public function test_nobody_sees_somebody_elses_saved_beats(): void
    {
        $other = $this->person();
        $s = $this->matched(['title' => 'Their private save'], 50, null, $other);
        app(Floor::class)->saveForLater($other, $s);

        $this->saved()->assertDontSee('Their private save')->assertSee('Nothing saved yet.');
    }

    public function test_not_for_me_is_not_offered_on_a_saved_beat_but_unsave_is(): void
    {
        $s = $this->matched(['title' => 'Kept']);
        app(Floor::class)->saveForLater($this->me, $s);

        $this->saved()->assertDontSee('Not for me')->assertSee('unsave('.$s->id.')', false);
    }

    public function test_the_page_renders(): void
    {
        $s = $this->matched(['title' => 'Kept']);
        app(Floor::class)->saveForLater($this->me, $s);

        $this->actingAs($this->me)->get(route('saved'))->assertOk()->assertSee('Kept');
    }
}

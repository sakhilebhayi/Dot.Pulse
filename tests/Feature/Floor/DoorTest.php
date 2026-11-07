<?php

namespace Tests\Feature\Floor;

use App\Livewire\Pulse\Floor\Door;
use App\Models\PulseDoor;
use App\Models\PulseSignal;
use Livewire\Livewire;

class DoorTest extends FloorTestCase
{
    private function door($as = null)
    {
        return Livewire::actingAs($as ?? $this->me)->test(Door::class);
    }

    public function test_the_page_says_honestly_what_a_door_is(): void
    {
        $this->door()
            ->assertSee('What a door is')
            ->assertSee('Strangers can knock once with a short card.')
            ->assertSee('contact details unlock only when you say yes')
            ->assertSee('Open the door');
    }

    public function test_a_person_opens_a_door_for_a_day_and_it_becomes_a_live_signal(): void
    {
        $c = $this->door()
            ->set('purpose', 'Looking for a baker to supply two shops')
            ->set('length', 'day')
            ->call('open')
            ->assertHasNoErrors()
            ->assertDispatched('pulse-door-changed')
            ->assertSee('Open until')
            ->assertSee('Looking for a baker to supply two shops')
            ->assertSee('No one has knocked yet.');

        $door = PulseDoor::first();
        $this->assertSame($this->me->id, $door->owner_id);
        $this->assertTrue($door->closes_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertSame('live', PulseSignal::where('kind', 'door')->value('status'));
    }

    public function test_a_door_can_stay_open_for_a_week(): void
    {
        $this->door()->set('purpose', 'Open to a coffee with a local baker')->set('length', 'week')->call('open');

        $this->assertTrue(PulseDoor::first()->closes_at->between(now()->addDays(6)->addHours(23), now()->addDays(7)->addHour()));
    }

    public function test_the_purpose_is_checked(): void
    {
        $this->door()->set('purpose', '')->call('open')->assertHasErrors(['purpose' => 'required']);
        $this->door()->set('purpose', 'short')->call('open')->assertHasErrors(['purpose' => 'min']);
        $this->door()->set('purpose', str_repeat('a', 200))->call('open')->assertHasErrors(['purpose' => 'max']);
        $this->door()->set('purpose', 'Call me on 082 123 4567 about work')->call('open')->assertHasErrors(['purpose']);
        $this->door()->set('purpose', 'See https://example.com for what I need')->call('open')->assertHasErrors(['purpose']);

        $this->assertSame(0, PulseDoor::count());
    }

    public function test_the_length_cannot_be_forged(): void
    {
        $this->door()->set('purpose', 'Looking for a baker to supply two shops')->set('length', 'forever')->call('open')->assertHasErrors(['length']);

        $this->assertSame(0, PulseDoor::count());
    }

    public function test_one_open_door_at_a_time_with_an_honest_message(): void
    {
        $c = $this->door()->set('purpose', 'Looking for a baker to supply two shops')->call('open');

        $this->assertSame(1, PulseDoor::count());
        $c->assertDontSee('Open a door</h2>', false);

        $this->door()->set('purpose', 'Another door for another thing')->call('open')->assertSet('failure', 'You already have a door open. Close it before opening another.');
        $this->assertSame(1, PulseDoor::count());
    }

    public function test_the_page_shows_how_many_have_knocked_and_only_the_count(): void
    {
        $this->door()->set('purpose', 'Looking for a baker to supply two shops')->call('open');
        $door = PulseDoor::first();
        $knocker = $this->person(['place' => $this->geo->jhb], ['name' => 'Knocker Secretname']);
        $door->forceFill(['knocks_count' => 2])->save();

        $this->door()->assertSee('2 knocks so far.')->assertSee('Answer them in')->assertDontSee('Secretname');
    }

    public function test_the_owner_closes_the_door_and_nobody_else_can(): void
    {
        $this->door()->set('purpose', 'Looking for a baker to supply two shops')->call('open');
        $door = PulseDoor::first();

        $intruder = $this->person();
        $this->door($intruder)->call('close', $door->id);
        $this->assertSame('open', $door->fresh()->status);

        $this->door()->call('close', $door->id)->assertDispatched('pulse-door-changed')->assertSee('Open a door');
        $this->assertSame('closed', $door->fresh()->status);
        $this->assertSame('expired', PulseSignal::where('kind', 'door')->value('status'));
    }

    public function test_a_door_can_be_opened_for_a_business_page_the_person_answers_for(): void
    {
        $entity = $this->dEntity($this->me, ['name' => 'Dlamini Studio']);

        $this->door()
            ->assertSee('Open it as')
            ->assertSee('Dlamini Studio')
            ->set('as', $entity->id)
            ->set('purpose', 'The studio is open to freelance printers')
            ->call('open')
            ->assertHasNoErrors();

        $this->assertSame($entity->id, PulseDoor::first()->entity_id);
    }

    public function test_a_door_for_a_page_the_person_does_not_answer_for_is_refused(): void
    {
        $stranger = $this->dEntity($this->person(), ['name' => 'Not Mine']);

        $this->door()->set('as', $stranger->id)->set('purpose', 'Pretending to be somebody else')->call('open')->assertSet('failure', 'You do not answer for that page.');

        $this->assertSame(0, PulseDoor::count());
    }

    public function test_a_person_who_has_not_confirmed_their_age_cannot_open_a_door(): void
    {
        \DB::table('pulse_profiles')->where('user_id', $this->me->id)->update(['age_confirmed_at' => null]);

        $this->door()->set('purpose', 'Looking for a baker to supply two shops')->call('open')->assertSet('failure', 'Pulse is for people 18 and over. Confirm your age in your profile first.');
    }

    public function test_the_page_renders(): void
    {
        $this->actingAs($this->me)->get(route('door'))->assertOk()->assertSee('Open a door');
    }
}

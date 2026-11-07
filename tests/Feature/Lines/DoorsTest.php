<?php

namespace Tests\Feature\Lines;

use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Services\Pulse\Lines\Doors;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class DoorsTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function doors(): Doors
    {
        return app(Doors::class);
    }

    public function test_opening_a_door_creates_a_live_public_signal_that_expires_with_it(): void
    {
        $owner = $this->person();

        $signal = $this->doors()->open($owner, 'Looking for a baker for Saturday', 'day');

        $this->assertSame('door', $signal->kind);
        $this->assertSame('live', $signal->status);
        $this->assertSame($owner->id, $signal->owner_id);
        $this->assertSame('Looking for a baker for Saturday', $signal->title);
        $door = PulseDoor::where('signal_id', $signal->id)->first();
        $this->assertSame($door->closes_at->timestamp, $signal->expires_at->timestamp);
        $this->assertTrue($door->closes_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertSame(PulseDoor::class, $signal->subject_type);

        $week = $this->doors()->open($this->person(), 'Open to meeting suppliers in Durban', 'week');
        $this->assertTrue(PulseDoor::where('signal_id', $week->id)->first()->closes_at->between(now()->addDays(6), now()->addDays(8)));
    }

    public function test_a_door_needs_a_real_purpose_without_links_and_a_valid_length(): void
    {
        $owner = $this->person();

        foreach ([['short', 'day', 'purpose'], ['Visit www.mysite.co.za to talk to me', 'day', 'purpose'], ['Call me on 082 123 4567 please', 'day', 'purpose'], ['A perfectly good purpose', 'month', 'length']] as [$purpose, $length, $field]) {
            try {
                $this->doors()->open($owner, $purpose, $length);
                $this->fail("refused: {$purpose}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_one_open_door_per_owner_age_gate_and_nobody_policy(): void
    {
        $owner = $this->person();
        $this->doors()->open($owner, 'Looking for a baker for Saturday', 'day');

        try {
            $this->doors()->open($owner, 'Looking for another baker', 'day');
            $this->fail('one door at a time');
        } catch (LineException $e) {
            $this->assertSame('exists', $e->reason);
        }

        try {
            $this->doors()->open($this->minor(), 'Looking for a baker for Saturday', 'day');
            $this->fail('age');
        } catch (LineException $e) {
            $this->assertSame('age', $e->reason);
        }

        $this->expectException(LineException::class);
        $this->doors()->open($this->policy($this->person(), 'nobody'), 'Looking for a baker for Saturday', 'day');
    }

    public function test_a_page_opens_a_door_through_a_responder_only(): void
    {
        $page = $this->page();
        $owner = $this->responderOf($page);
        $editor = $this->member($page, null, 'editor');

        $signal = $this->doors()->open($page, 'Open to meeting new suppliers this week', 'week', $owner);
        $this->assertSame($page->id, $signal->entity_id);
        $this->assertNull($signal->owner_id);

        foreach ([$editor, $this->person()] as $nope) {
            try {
                $this->doors()->open($page, 'Open to meeting suppliers', 'day', $nope);
                $this->fail('not a responder');
            } catch (AuthorizationException) {
            }
        }

        $this->expectException(AuthorizationException::class);
        $this->doors()->open($page, 'Open to meeting suppliers', 'day'); // nobody named
    }

    public function test_a_stranger_knocks_once_and_contact_details_unlock_only_on_accept(): void
    {
        $owner = $this->person();
        $stranger = $this->person();
        $signal = $this->doors()->open($owner, 'Looking for a baker for Saturday', 'day');
        $budget = app(KnockBudget::class);
        $before = $budget->remaining($stranger);

        $c = $this->doors()->knockOnDoor($stranger, $signal, $this->card(['what' => 'I bake wedding cakes']));

        $this->assertSame('requested', $c->status);
        $this->assertSame('door', $c->context_type);
        $this->assertSame('intro', $c->kind);
        $this->assertSame($before - 1, $budget->remaining($stranger));
        $this->assertSame(1, PulseDoor::first()->knocks_count);

        // Knocking again returns the same request: once only, and it costs nothing more.
        $again = $this->doors()->knockOnDoor($stranger, $signal, $this->card(['what' => 'Again please']));
        $this->assertSame($c->id, $again->id);
        $this->assertSame(1, PulseDoor::first()->knocks_count);
        $this->assertSame($before - 1, $budget->remaining($stranger));

        // A first message with contact details is refused; after accept they flow.
        try {
            app(LineService::class)->knock($this->person(), ['door' => PulseDoor::first()->id], 'intro', $this->card(['what' => 'call 0821234567']));
            $this->fail('no contact details');
        } catch (ValidationException) {
        }

        app(LineService::class)->accept($owner, $c);
        $msg = app(LineService::class)->send($stranger, $c->fresh(), 'My number is 082 123 4567');
        $this->assertNotNull($msg->id);
    }

    public function test_a_door_holds_a_limited_number_of_knocks(): void
    {
        config(['pulse.lines.door_knock_cap' => 2]);
        $owner = $this->person();
        $signal = $this->doors()->open($owner, 'Looking for a baker for Saturday', 'day');

        $this->doors()->knockOnDoor($this->person(), $signal, $this->card());
        $this->doors()->knockOnDoor($this->person(), $signal, $this->card());

        try {
            $this->doors()->knockOnDoor($this->person(), $signal, $this->card());
            $this->fail('full');
        } catch (LineException $e) {
            $this->assertSame('limit', $e->reason);
        }

        $this->assertSame(2, PulseConversation::count());
    }

    public function test_nobody_can_knock_on_a_closed_or_lapsed_door(): void
    {
        $owner = $this->person();
        $signal = $this->doors()->open($owner, 'Looking for a baker for Saturday', 'day');
        $door = PulseDoor::first();

        $this->doors()->close($owner, $signal);
        $this->assertSame('closed', $door->fresh()->status);
        $this->assertSame('expired', $signal->fresh()->status);

        $this->expectException(LineException::class);
        $this->doors()->knockOnDoor($this->person(), $door->fresh(), $this->card());
    }

    public function test_only_the_owner_can_close_a_door(): void
    {
        $owner = $this->person();
        $signal = $this->doors()->open($owner, 'Looking for a baker for Saturday', 'day');

        $this->expectException(AuthorizationException::class);
        $this->doors()->close($this->person(), $signal);
    }

    public function test_a_door_does_not_get_around_a_block_or_the_budget(): void
    {
        $owner = $this->person();
        $blocked = $this->person();
        $signal = $this->doors()->open($owner, 'Looking for a baker for Saturday', 'day');
        app(\App\Services\Pulse\Lines\BlockService::class)->block($owner, $blocked);

        try {
            $this->doors()->knockOnDoor($blocked, $signal, $this->card());
            $this->fail('blocked');
        } catch (LineException $e) {
            $this->assertSame('not_allowed', $e->reason);
        }

        $broke = $this->person();
        while (app(KnockBudget::class)->remaining($broke) > 0) {
            app(KnockBudget::class)->spend($broke);
        }
        $this->expectException(LineException::class);
        $this->doors()->knockOnDoor($broke, $signal, $this->card());
    }

    public function test_the_door_shows_up_in_the_owners_consent_ledger_with_a_way_to_close_it(): void
    {
        $owner = $this->person();
        $this->doors()->open($owner, 'Looking for a baker for Saturday', 'day');

        $row = collect(app(\App\Services\Pulse\Lines\ConsentLedger::class)->entries($owner))->firstWhere('kind', 'door');
        $this->assertStringContainsString('Looking for a baker for Saturday', $row['sentence']);

        app(\App\Services\Pulse\Lines\ConsentLedger::class)->withdraw($owner, $row['key']);
        $this->assertSame('closed', PulseDoor::first()->status);
        $this->assertNull(DB::table('pulse_signals')->where('kind', 'door')->where('status', 'live')->first());
    }
}

<?php

namespace Tests\Feature\Floor;

use App\Livewire\Pulse\Floor\Context;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Your desk, under the round: one label and value per row, real counts of the person's own records, and the two
 * counts the shell badge polls (Lines with something new, requests waiting) read on the server so a person sees them
 * without waiting for the poll.
 */
class DeskTest extends FloorTestCase
{
    private function desk($as = null)
    {
        return Livewire::actingAs($as ?? $this->me)->test(Context::class);
    }

    private function conversation(int $initiator, int $recipient, string $status, array $extra = []): void
    {
        DB::table('pulse_conversations')->insert($extra + [
            'type' => 'direct', 'kind' => 'enquiry', 'status' => $status, 'initiator_id' => $initiator,
            'recipient_id' => $recipient, 'context_type' => 'need', 'context_id' => 1, 'card' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_desk_is_seven_label_value_rows_with_honest_zeros(): void
    {
        $html = $this->desk()->html();

        foreach (['Door', 'Lines', 'Requests', 'Needs open', 'Roles live', 'Saved', 'Quiet'] as $label) {
            $this->assertStringContainsString('<dt>'.$label.'</dt>', $html);
        }

        $this->desk()->assertSee('Closed')->assertSee('None waiting')->assertSee('Open a door');
    }

    public function test_a_request_waiting_for_the_person_is_counted_and_nobody_elses_is(): void
    {
        $stranger = $this->person();
        $other = $this->person();

        $this->conversation($stranger->id, $this->me->id, 'requested');
        $this->conversation($stranger->id, $other->id, 'requested');
        $this->conversation($stranger->id, $other->id, 'requested');

        $this->desk()->assertSee('1 waiting for an answer');
        $this->desk($other)->assertSee('2 waiting for an answer');
        $this->desk($stranger)->assertDontSee('waiting for an answer');
    }

    public function test_a_line_with_a_new_message_is_counted_for_the_side_that_has_not_read_it(): void
    {
        $stranger = $this->person();
        $this->conversation($stranger->id, $this->me->id, 'open', ['last_message_side' => 'initiator', 'last_message_at' => now()]);

        $this->desk()->assertSee('1 with something new')->assertSee('Open Lines');
        $this->desk($stranger)->assertDontSee('with something new');
    }

    public function test_the_floor_page_draws_the_desk_once_and_the_keys_in_the_foot(): void
    {
        $html = $this->actingAs($this->me)->get(route('floor'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-floor-desk'));
        $this->assertSame(1, substr_count($html, 'data-floor-foot'));
        $this->assertStringNotContainsString('class="sh-context"', $html, 'the right-hand context column is gone');
    }
}

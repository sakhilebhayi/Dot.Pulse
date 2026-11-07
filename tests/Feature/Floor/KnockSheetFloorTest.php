<?php

namespace Tests\Feature\Floor;

use App\Livewire\Pulse\KnockSheet;
use Livewire\Livewire;

/** The shared knock sheet as the Floor uses it: one bare sheet, pointed at a beat by an event. */
class KnockSheetFloorTest extends FloorTestCase
{
    private function sheet()
    {
        return Livewire::actingAs($this->me)->test(KnockSheet::class, ['bare' => true]);
    }

    private function spec(array $target, array $more = []): array
    {
        return array_merge(['target' => $target, 'kind' => 'enquiry', 'contextType' => 'offer', 'contextId' => null, 'label' => 'Knock', 'title' => 'Bookkeeping for small shops'], $more);
    }

    public function test_a_bare_sheet_has_no_button_and_is_closed(): void
    {
        $this->sheet()->assertSet('open', false)->assertDontSee('wire:click="openSheet"', false);
    }

    public function test_the_pulse_knock_event_points_the_sheet_at_a_beat_and_opens_it(): void
    {
        [$signal, $entity] = $this->offerFor();

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => $entity->id], ['contextId' => (int) $signal->subject_id]))
            ->assertSet('open', true)
            ->assertSet('target', ['entity' => $entity->id])
            ->assertSet('contextType', 'offer')
            ->assertSet('heading', 'Bookkeeping for small shops')
            ->assertSee('About: Bookkeeping for small shops')
            ->assertSee('Say what you want.');
    }

    public function test_a_spec_from_the_browser_is_cleaned_before_it_is_used(): void
    {
        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => 'not a number'], ['kind' => 'enquiry']))
            ->assertSet('open', false);

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => 1], ['kind' => 'wire money']))
            ->assertSet('open', false);

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => 1], ['contextType' => 'user']))
            ->assertSet('contextType', null);
    }

    public function test_knocking_sends_a_line_and_the_sheet_says_what_happens_next(): void
    {
        [$signal, $entity] = $this->offerFor();
        $entity->forceFill(['contact_policy' => 'requests'])->save();

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => $entity->id], ['contextId' => (int) $signal->subject_id]))
            ->set('what', 'Monthly books for a three person studio')
            ->set('whenOrQty', 'From December')
            ->call('send')
            ->assertSet('sent', true)
            ->assertSet('lineStatus', 'requested')
            ->assertDispatched('pulse-knocked')
            ->assertSee('Now they decide.')
            ->assertSee('A request closes by itself after 14 days.');

        $this->assertDatabaseCount('pulse_conversations', 1);
    }

    public function test_a_page_that_takes_messages_from_anyone_opens_the_line_at_once(): void
    {
        [$signal, $entity] = $this->offerFor();

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => $entity->id], ['contextId' => (int) $signal->subject_id]))
            ->set('what', 'Can you start in January')
            ->call('send')
            ->assertSet('lineStatus', 'open')
            ->assertSee('The line is open.')
            ->assertSee('Open the line');
    }

    public function test_when_the_contact_gate_refuses_the_sheet_shows_its_own_words_and_no_form(): void
    {
        [, $entity] = $this->offerFor();
        $entity->forceFill(['contact_policy' => 'nobody'])->save();

        $c = $this->sheet()->dispatch('pulse-knock', spec: $this->spec(['entity' => $entity->id]));

        $c->assertSee('They are not taking new messages.')->assertDontSee('What do you want?')->assertSee('Close');
    }

    public function test_the_age_refusal_links_to_the_profile(): void
    {
        [, $entity] = $this->offerFor();
        \DB::table('pulse_profiles')->where('user_id', $this->me->id)->update(['age_confirmed_at' => null]);

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => $entity->id]))
            ->assertSee('Confirm your age in your profile')
            ->assertSee('Confirm your age');
    }

    public function test_the_first_contact_budget_is_shown_honestly(): void
    {
        [, $entity] = $this->offerFor();

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => $entity->id]))
            ->assertSee('first contacts left this week');
    }

    public function test_a_link_in_the_first_message_is_refused_on_the_field_it_is_in(): void
    {
        [, $entity] = $this->offerFor();

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => $entity->id]))
            ->set('what', 'See https://example.com/deal for what I want')
            ->call('send')
            ->assertSet('sent', false)
            ->assertHasErrors(['what']);

        $this->assertDatabaseCount('pulse_conversations', 0);
    }

    public function test_the_what_field_is_required_and_the_sheet_stays_open(): void
    {
        [, $entity] = $this->offerFor();

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => $entity->id]))
            ->call('send')
            ->assertHasErrors(['what' => 'required'])
            ->assertSet('open', true);
    }

    public function test_pointing_the_sheet_at_another_beat_starts_with_a_clean_form(): void
    {
        [, $a] = $this->offerFor();
        [, $b] = $this->offerFor('Another');

        $this->sheet()
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => $a->id]))
            ->set('what', 'Half written')
            ->dispatch('pulse-knock', spec: $this->spec(['entity' => $b->id]))
            ->assertSet('what', '')
            ->assertSet('target', ['entity' => $b->id]);
    }

    public function test_the_sheet_is_a_native_dialog_that_the_server_state_drives(): void
    {
        $html = $this->sheet()->html();

        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('wire:ignore.self', $html);
        $this->assertStringContainsString('aria-labelledby=', $html);
        $this->assertStringContainsString('data-pk-close', $html);
        $this->assertStringContainsString('autofocus', $html);
    }

    public function test_the_button_form_still_works_the_way_it_always_did(): void
    {
        [, $entity] = $this->offerFor();

        Livewire::actingAs($this->me)
            ->test(KnockSheet::class, ['target' => ['entity' => $entity->id], 'kind' => 'enquiry', 'label' => 'Ask'])
            ->assertSee('Ask')
            ->call('openSheet')
            ->assertSet('open', true);
    }
}

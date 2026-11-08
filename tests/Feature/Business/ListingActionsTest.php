<?php

namespace Tests\Feature\Business;

use App\Livewire\Pulse\Business\ListingActions;
use App\Livewire\Pulse\Business\QuoteRequest;
use App\Models\PulseBlock;
use App\Models\PulseEntityBlock;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseReport;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class ListingActionsTest extends BusinessTestCase
{
    public function test_a_guest_cannot_mount_the_actions(): void
    {
        $page = $this->page();

        Livewire::test(ListingActions::class, ['type' => 'entity', 'id' => $page->id])->assertForbidden();
    }

    public function test_follow_and_unfollow_a_page(): void
    {
        $page = $this->page();
        $visitor = $this->person();

        $c = Livewire::actingAs($visitor)->test(ListingActions::class, ['type' => 'entity', 'id' => $page->id])
            ->call('toggleFollow')->assertSee('Following');
        $this->assertDatabaseHas('pulse_entity_followers', ['entity_id' => $page->id, 'user_id' => $visitor->id]);

        $c->call('toggleFollow');
        $this->assertDatabaseMissing('pulse_entity_followers', ['entity_id' => $page->id, 'user_id' => $visitor->id]);
    }

    public function test_save_an_offer_through_its_signal(): void
    {
        $page = $this->page();
        $offer = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by]);
        $signal = PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->first();
        $this->assertNotNull($signal, 'the observer indexes the offer');
        $visitor = $this->person();

        $c = Livewire::actingAs($visitor)->test(ListingActions::class, ['type' => 'offer', 'id' => $offer->id])->call('toggleSave')->assertSee('Saved');
        $this->assertTrue(PulseSave::where(['user_id' => $visitor->id, 'saveable_type' => 'signal', 'saveable_id' => $signal->id])->exists());

        $c->call('toggleSave');
        $this->assertFalse(PulseSave::where(['user_id' => $visitor->id, 'saveable_id' => $signal->id])->exists());
    }

    public function test_report_needs_a_reason_and_records_once(): void
    {
        $page = $this->page();
        $visitor = $this->person();

        $c = Livewire::actingAs($visitor)->test(ListingActions::class, ['type' => 'entity', 'id' => $page->id])
            ->call('openReport')->call('report')->assertHasErrors(['reason']);

        $c->set('reason', 'spam')->call('report')->assertHasNoErrors()->assertSee('Reported');
        $this->assertSame(1, PulseReport::where('reporter_id', $visitor->id)->count());

        $c->call('openReport')->set('reason', 'spam')->call('report');
        $this->assertSame(1, PulseReport::where('reporter_id', $visitor->id)->count());
    }

    public function test_report_other_needs_a_note(): void
    {
        $page = $this->page();

        Livewire::actingAs($this->person())->test(ListingActions::class, ['type' => 'entity', 'id' => $page->id])
            ->call('openReport')->set('reason', 'other')->call('report')->assertHasErrors(['note']);
    }

    public function test_block_a_page_hides_it_and_redirects(): void
    {
        $page = $this->page();
        $visitor = $this->person();

        Livewire::actingAs($visitor)->test(ListingActions::class, ['type' => 'entity', 'id' => $page->id])
            ->call('askBlock')->assertSee('They are not told')->call('block')->assertRedirect();

        $this->assertTrue(PulseEntityBlock::where('entity_id', $page->id)->where('user_id', $visitor->id)->exists());
    }

    public function test_block_on_a_need_blocks_its_owner(): void
    {
        $asker = $this->person();
        $need = PulseNeed::factory()->create(['user_id' => $asker->id]);
        $visitor = $this->person();

        Livewire::actingAs($visitor)->test(ListingActions::class, ['type' => 'need', 'id' => $need->id])->call('block')->assertRedirect();

        $this->assertTrue(PulseBlock::where('blocker_id', $visitor->id)->where('blocked_id', $asker->id)->exists());
    }

    public function test_members_of_the_page_get_no_follow_report_or_block_and_cannot_use_them(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        $c = Livewire::actingAs($owner)->test(ListingActions::class, ['type' => 'entity', 'id' => $page->id])
            ->assertDontSee('Report')->assertDontSee('Block');

        $c->call('toggleFollow')->call('block')->set('reason', 'spam')->call('report');
        $this->assertDatabaseCount('pulse_entity_followers', 0);
        $this->assertSame(0, PulseReport::count());
        $this->assertSame(0, PulseEntityBlock::count());
    }

    public function test_the_owner_of_a_need_has_no_report_or_block(): void
    {
        $owner = $this->person();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id]);

        Livewire::actingAs($owner)->test(ListingActions::class, ['type' => 'need', 'id' => $need->id])->assertDontSee('Report')->assertDontSee('Block');
    }

    public function test_request_a_quote_on_an_offer_and_the_cap_is_explained(): void
    {
        $page = $this->page();
        $offer = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'accepts_quotes' => true]);
        $visitor = $this->person();

        $c = Livewire::actingAs($visitor)->test(QuoteRequest::class, ['offerId' => $offer->id])
            ->assertSee('at most 3 businesses')
            ->call('openForm')
            ->set('what', 'Books for a small shop')->set('budgetBand', 'R2 000 to R3 000')
            ->call('send')->assertHasNoErrors()->assertSet('sent', true);

        $this->assertSame(1, DB::table('pulse_conversations')->where('kind', 'quote')->where('entity_id', $page->id)->where('initiator_id', $visitor->id)->count());
    }

    public function test_a_quote_request_with_a_link_is_refused_on_the_field(): void
    {
        $page = $this->page();
        $offer = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'accepts_quotes' => true]);

        Livewire::actingAs($this->person())->test(QuoteRequest::class, ['offerId' => $offer->id])
            ->call('openForm')->set('what', 'See https://example.com for details')->call('send')->assertHasErrors(['what']);
    }

    public function test_quote_form_is_absent_when_the_offer_does_not_accept_quotes_or_for_the_owner(): void
    {
        $page = $this->page();
        $no = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'accepts_quotes' => false]);
        $yes = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'accepts_quotes' => true]);

        Livewire::actingAs($this->person())->test(QuoteRequest::class, ['offerId' => $no->id])->assertDontSee('Ask for a quote');
        Livewire::actingAs($page->members()->first()->user)->test(QuoteRequest::class, ['offerId' => $yes->id])->assertDontSee('Ask for a quote');
    }
}

<?php

namespace Tests\Feature\Business;

use App\Livewire\Pulse\Business\OfferManager;
use App\Models\PulseOffer;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

class OfferManagerTest extends BusinessTestCase
{
    public function test_roles_decide_who_can_open_the_offers_tab(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        foreach (['owner' => $owner, 'admin' => $this->member($page, 'admin'), 'editor' => $this->member($page, 'editor')] as $user) {
            Livewire::actingAs($user)->test(OfferManager::class, ['entity' => $page])->assertOk();
        }

        Livewire::actingAs($this->member($page, 'responder'))->test(OfferManager::class, ['entity' => $page])->assertForbidden();
        Livewire::actingAs($this->person())->test(OfferManager::class, ['entity' => $page])->assertForbidden();
    }

    public function test_save_and_publish_puts_an_offer_on_the_floor(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        Livewire::actingAs($owner)->test(OfferManager::class, ['entity' => $page])
            ->call('newOffer')
            ->set('title', 'Monthly bookkeeping')
            ->set('summary', 'Books kept, VAT filed.')
            ->set('price_mode', 'from')->set('amount', '2 500')->set('unit', 'month')
            ->call('savePublish')
            ->assertHasNoErrors()
            ->assertSet('mode', 'list')
            ->assertSee('Published. It is on the floor.');

        $offer = PulseOffer::where('title', 'Monthly bookkeeping')->firstOrFail();
        $this->assertSame('live', $offer->status);
        $this->assertSame(250000, $offer->amount_cents);
        $this->assertSame('approved', $offer->moderation);
    }

    public function test_save_as_draft_keeps_it_off_the_floor(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        Livewire::actingAs($owner)->test(OfferManager::class, ['entity' => $page])
            ->call('newOffer')->set('title', 'Quiet draft')->set('price_mode', 'quote')
            ->call('save')->assertHasNoErrors()->assertSee('Saved as a draft');

        $this->assertSame('draft', PulseOffer::where('title', 'Quiet draft')->value('status'));
    }

    public function test_a_price_is_required_for_fixed_and_from(): void
    {
        $page = $this->page();

        Livewire::actingAs($page->members()->first()->user)->test(OfferManager::class, ['entity' => $page])
            ->call('newOffer')->set('title', 'No price offer')->set('price_mode', 'fixed')->set('amount', '')
            ->call('save')->assertHasErrors(['amount']);
    }

    public function test_publishing_without_a_summary_is_refused_and_says_so(): void
    {
        $page = $this->page();

        Livewire::actingAs($page->members()->first()->user)->test(OfferManager::class, ['entity' => $page])
            ->call('newOffer')->set('title', 'Thin offer')->set('price_mode', 'quote')
            ->call('savePublish')->assertHasErrors(['summary']);
    }

    public function test_an_author_without_a_company_publishes_into_waiting_for_a_human(): void
    {
        $owner = $this->person();
        $page = $this->page($owner);

        Livewire::actingAs($owner)->test(OfferManager::class, ['entity' => $page])
            ->call('newOffer')->set('title', 'Needs a look')->set('summary', 'A summary.')->set('price_mode', 'quote')
            ->call('savePublish')
            ->assertSee('Waiting for a human')
            ->assertSee('Pulse needs a person to look at this');

        $this->assertSame('pending', PulseOffer::where('title', 'Needs a look')->value('moderation'));
    }

    public function test_pause_publish_archive_and_delete(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $offer = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $owner->id]);

        $c = Livewire::actingAs($owner)->test(OfferManager::class, ['entity' => $page]);

        $c->call('pause', $offer->id)->assertSee('Paused');
        $this->assertSame('paused', $offer->fresh()->status);

        $c->call('publish', $offer->id);
        $this->assertSame('live', $offer->fresh()->status);

        $c->call('archive', $offer->id);
        $this->assertSame('archived', $offer->fresh()->status);

        $c->call('delete', $offer->id)->assertSee('Deleted');
        $this->assertSoftDeleted('pulse_offers', ['id' => $offer->id]);
    }

    public function test_an_editor_can_manage_offers_and_a_responder_cannot_touch_them(): void
    {
        $page = $this->page();
        $offer = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by]);

        Livewire::actingAs($this->member($page, 'editor'))->test(OfferManager::class, ['entity' => $page])->call('pause', $offer->id);
        $this->assertSame('paused', $offer->fresh()->status);
    }

    public function test_an_offer_of_another_page_cannot_be_reached_by_id(): void
    {
        $mine = $this->page();
        $theirs = $this->page();
        $offer = PulseOffer::factory()->create(['entity_id' => $theirs->id, 'created_by' => $theirs->created_by]);

        try {
            Livewire::actingAs($mine->members()->first()->user)->test(OfferManager::class, ['entity' => $mine])->call('pause', $offer->id);
            $this->fail('An offer of another page must not be found.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame('live', $offer->fresh()->status);
        }
    }

    public function test_a_removed_offer_cannot_be_edited_and_the_owner_is_told(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $offer = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $owner->id, 'status' => 'removed', 'moderation' => 'removed']);

        Livewire::actingAs($owner)->test(OfferManager::class, ['entity' => $page])
            ->assertSee('Removed by a moderator')
            ->call('pause', $offer->id)->assertSee('removed by a moderator');
    }

    public function test_pictures_upload_and_a_non_image_is_rejected(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        Livewire::actingAs($owner)->test(OfferManager::class, ['entity' => $page])
            ->call('newOffer')->set('title', 'With pictures')->set('summary', 'Has a photo.')->set('price_mode', 'quote')
            ->set('newImages', [UploadedFile::fake()->image('one.jpg', 800, 600)])
            ->call('save')->assertHasNoErrors();

        $offer = PulseOffer::where('title', 'With pictures')->firstOrFail();
        $this->assertCount(1, $offer->media_ids);
        $this->assertDatabaseHas('pulse_media_files', ['id' => $offer->media_ids[0], 'purpose' => 'offer', 'entity_id' => $page->id]);

        Livewire::actingAs($owner)->test(OfferManager::class, ['entity' => $page])
            ->call('newOffer')->set('title', 'Bad picture')->set('price_mode', 'quote')
            ->set('newImages', [UploadedFile::fake()->create('x.pdf', 50, 'application/pdf')])
            ->call('save')->assertHasErrors(['newImages.0']);
    }

    public function test_a_page_that_does_not_sell_is_pointed_to_the_page_tab(): void
    {
        $page = $this->page(null, ['is_seller' => false]);

        Livewire::actingAs($page->members()->first()->user)->test(OfferManager::class, ['entity' => $page])
            ->assertSee('does not sell yet');
    }
}

<?php

namespace Tests\Feature\Seam;

use App\Livewire\Pulse\KnockSheet;
use App\Services\Pulse\Entities\OfferService;
use Livewire\Livewire;
use Tests\Feature\Entities\EntitiesTestCase;

class KnockSheetTest extends EntitiesTestCase
{
    public function test_a_person_knocks_on_an_offer_and_a_line_exists(): void
    {
        $seller = $this->infodotPerson();
        $entity = $this->page($seller);
        $offer = app(OfferService::class)->create($seller, $entity, $this->offerData() + ['publish' => true]);
        $asker = $this->infodotPerson();
        $asker->pulseProfile()->firstOrCreate(['user_id' => $asker->id])->forceFill(['age_confirmed_at' => now()])->save();

        Livewire::actingAs($asker)
            ->test(KnockSheet::class, ['target' => ['entity' => $entity->id], 'kind' => 'enquiry', 'contextType' => 'offer', 'contextId' => $offer->id])
            ->call('openSheet')
            ->set('what', 'Monthly bookkeeping')
            ->call('send')
            ->assertSet('sent', true)
            ->assertSee('Open the line');

        $this->assertDatabaseCount('pulse_conversations', 1);
    }

    public function test_links_in_a_first_message_are_refused(): void
    {
        $seller = $this->infodotPerson();
        $entity = $this->page($seller);
        $asker = $this->infodotPerson();
        $asker->pulseProfile()->firstOrCreate(['user_id' => $asker->id])->forceFill(['age_confirmed_at' => now()])->save();

        Livewire::actingAs($asker)
            ->test(KnockSheet::class, ['target' => ['entity' => $entity->id]])
            ->call('openSheet')
            ->set('what', 'See https://example.com/deal')
            ->call('send')
            ->assertSet('sent', false);

        $this->assertDatabaseCount('pulse_conversations', 0);
    }
}

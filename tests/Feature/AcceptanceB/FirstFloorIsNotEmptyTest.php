<?php

namespace Tests\Feature\AcceptanceB;

use App\Livewire\Pulse\Need\NeedForm;
use App\Models\PulseMatch;
use App\Models\PulseSignal;
use App\Services\Pulse\Entities\OfferService;
use Livewire\Livewire;
use Tests\Feature\Business\BusinessTestCase;

/**
 * Found by the browser acceptance run (suite B, criterion 20): a brand-new person who posted a need saw
 * "Nothing matches yet" until the ten minute scheduled job ran, and the seller whose offer fits did not see the need
 * until then either. Publishing now matches at once; the scheduled run still catches everything else up.
 */
class FirstFloorIsNotEmptyTest extends BusinessTestCase
{
    public function test_posting_a_need_matches_it_to_sellers_and_the_poster_to_offers_at_once(): void
    {
        $seller = $this->infodotPerson();
        $entity = $this->page($seller);
        $offer = app(OfferService::class)->create($seller, $entity, [
            'title' => 'Geyser repair and replacement', 'summary' => 'Same week geyser repairs.', 'category' => 'Plumbing',
            'tags' => ['plumbing', 'geyser'], 'price_mode' => 'quote', 'publish' => true,
        ]);
        $this->assertSame('live', $offer->fresh()->status);

        $asker = $this->infodotPerson();

        Livewire::actingAs($asker)->test(NeedForm::class)
            ->set('title', 'Need a plumber to fix a leaking geyser')
            ->set('details', 'It leaks every morning.')
            ->call('addTag', 'plumbing')
            ->set('reach', 'national')
            ->call('save')->assertHasNoErrors();

        $offerSignal = PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->firstOrFail();
        $needSignal = PulseSignal::where('subject_type', 'need')->firstOrFail();

        $this->assertTrue(PulseMatch::where('user_id', $asker->id)->where('signal_id', $offerSignal->id)->exists(), 'the poster sees the offer');
        $this->assertTrue(PulseMatch::where('user_id', $seller->id)->where('signal_id', $needSignal->id)->exists(), 'the seller sees the need');
    }

    public function test_what_a_person_is_told_about_a_held_listing_says_who_looks_and_how_long(): void
    {
        config(['pulse.moderation.review_target' => 'one working day']);
        $note = pulse_review_note();

        $this->assertStringContainsString('in the order they arrive', $note);
        $this->assertStringContainsString('one working day', $note);
        $this->assertStringContainsString('You are told the moment it is decided', $note);
        $this->assertStringNotContainsString('usually within a day', $note, 'a target is said as a target, not as a promise');
    }
}

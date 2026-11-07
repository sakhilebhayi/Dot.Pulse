<?php

namespace Tests\Feature\Seam;

use App\Models\PulseConversation;
use App\Services\Pulse\Discovery\Floor;
use App\Services\Pulse\Discovery\Matcher;
use App\Services\Pulse\Entities\NeedService;
use App\Services\Pulse\Entities\OfferService;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use Tests\Feature\Entities\EntitiesTestCase;

/**
 * The three backend modules were built apart. This walks one real story through all of them with no fixtures of
 * their own: a business publishes an offer, a person says what they need, the floor introduces them with a reason,
 * the person knocks, the business accepts, they talk, and both confirm the result.
 */
class EndToEndSeamTest extends EntitiesTestCase
{
    public function test_a_need_meets_an_offer_becomes_a_line_and_ends_in_a_confirmed_outcome(): void
    {
        $seller = $this->infodotPerson();
        $entity = $this->page($seller);
        $offer = app(OfferService::class)->create($seller, $entity, $this->offerData() + ['publish' => true]);
        $this->assertSame('live', $offer->fresh()->status);

        $asker = $this->infodotPerson();
        $asker->pulseProfile()->firstOrCreate(['user_id' => $asker->id])->forceFill(['age_confirmed_at' => now()])->save();
        $seller->pulseProfile()->firstOrCreate(['user_id' => $seller->id])->forceFill(['age_confirmed_at' => now()])->save();

        $need = app(NeedService::class)->create($asker, [
            'title' => 'Need a bookkeeper for my shop',
            'summary' => 'Monthly books and VAT for a small shop.',
            'kind' => 'need',
            'tags' => ['accounting', 'vat'],
            'reach' => 'national',
            'publish' => true,
        ]);

        // The asker's floor shows the offer with a plain reason.
        app(Matcher::class)->forUser($asker);
        $round = app(Floor::class)->round($asker, 'all');
        $beat = collect($round['beats'])->first(fn ($b) => $b->kind === 'offer');
        $this->assertNotNull($beat, 'the offer reaches the person who needs it');
        $this->assertNotEmpty($beat->reasons);
        $this->assertNotSame('', $beat->reasons[0]['text']);

        // They knock; the business accepts; they talk; both confirm a result.
        $line = app(LineService::class)->knock($asker, ['entity' => $entity->id], 'enquiry', [
            'what' => 'Monthly bookkeeping and VAT',
            'when_or_qty' => 'From next month',
            'where' => 'Johannesburg',
            'budget_band' => 'R2 000 to R3 000',
        ], $offer);
        // A page that takes contact from anyone opens straight away; a stricter one waits for a yes.
        $this->assertContains($line->status, ['requested', 'open']);

        if ($line->status === 'requested') {
            app(LineService::class)->accept($seller, $line);
        }
        $this->assertSame('open', $line->fresh()->status);

        app(LineService::class)->send($asker, $line->fresh(), 'Can we start on the first?');
        app(LineService::class)->send($seller, $line->fresh(), 'Yes. I will send the engagement letter.');

        app(OutcomeService::class)->propose($asker, $line->fresh(), 'won');
        $this->assertFalse(OutcomeService::confirmed($line->fresh()));
        app(OutcomeService::class)->confirm($seller, $line->fresh());
        $this->assertTrue(OutcomeService::confirmed($line->fresh()));

        $this->assertInstanceOf(PulseConversation::class, $line);
    }
}

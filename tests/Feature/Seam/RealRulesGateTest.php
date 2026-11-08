<?php

namespace Tests\Feature\Seam;

use App\Services\Pulse\Entities\OfferService;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Entities\EntitiesTestCase;

/** The entity tests fake the rules; this one uses the real Discovery\Moderation\LocalRules to catch shape drift. */
class RealRulesGateTest extends EntitiesTestCase
{
    public function test_a_suspicious_offer_is_held_or_refused_without_an_error(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);

        try {
            $offer = app(OfferService::class)->create($owner, $entity, $this->offerData([
                'title' => 'URGENT!!! PAY A FEE TO GET YOUR LEARNERSHIP',
                'summary' => 'WhatsApp 0821234567 now http://a.example http://b.example http://c.example http://d.example',
                'description' => 'Send proof of payment. Advance fee. WhatsApp 0821234567. http://a.example http://b.example http://c.example',
            ]) + ['publish' => true]);

            $this->assertContains($offer->moderation, ['pending', 'held', 'removed']);
            $this->assertNotSame('live', $offer->status === 'live' && $offer->moderation === 'approved' ? 'live' : 'not-live');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }
}

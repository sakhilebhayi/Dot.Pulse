<?php

namespace Tests\Feature\Entities;

use App\Models\PulseMedia;
use App\Models\PulseOffer;
use App\Models\PulseSignal;
use App\Services\Pulse\Entities\ListingModeration;
use App\Services\Pulse\Entities\OfferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OfferServiceTest extends EntitiesTestCase
{
    private function service(): OfferService
    {
        return app(OfferService::class);
    }

    private function signal(PulseOffer $offer): ?PulseSignal
    {
        return PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->first();
    }

    public function test_an_offer_starts_as_a_draft_with_no_signal(): void
    {
        $owner = $this->infodotPerson();
        $entity = $this->page($owner);

        $offer = $this->service()->create($owner, $entity, $this->offerData());

        $this->assertSame('draft', $offer->status);
        $this->assertSame('monthly-bookkeeping-for-small-businesses', $offer->slug);
        $this->assertNull($this->signal($offer));
    }

    public function test_publish_makes_it_live_for_an_approved_author(): void
    {
        $owner = $this->infodotPerson();
        $entity = $this->page($owner);
        $offer = $this->service()->create($owner, $entity, $this->offerData());

        $offer = $this->service()->publish($owner, $offer);

        $this->assertSame('live', $offer->status);
        $this->assertSame('approved', $offer->moderation);
        $this->assertNotNull($offer->published_at);
        $this->assertSame('live', $this->signal($offer)->status);
        $this->assertTrue($offer->isLive());
    }

    public function test_without_rules_a_stranger_publishes_into_pending_and_the_signal_is_held(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);

        $offer = $this->service()->create($owner, $entity, $this->offerData() + ['publish' => true]);

        $this->assertSame('live', $offer->status);
        $this->assertSame('pending', $offer->moderation);
        $this->assertFalse($offer->isLive());
        $this->assertSame('held', $this->signal($offer)->status);
        $this->assertNull(PulseOffer::live()->find($offer->id));
    }

    public function test_an_infodot_member_with_a_removed_listing_is_no_longer_trusted(): void
    {
        $owner = $this->infodotPerson();
        $entity = $this->page($owner);
        PulseOffer::factory()->create(['entity_id' => $entity->id, 'created_by' => $owner->id, 'moderation' => 'removed']);

        $offer = $this->service()->create($owner, $entity, $this->offerData() + ['publish' => true]);

        $this->assertSame('pending', $offer->moderation);
    }

    public function test_pause_publish_archive_delete_keep_the_signal_in_step(): void
    {
        $owner = $this->infodotPerson();
        $entity = $this->page($owner);
        $offer = $this->service()->create($owner, $entity, $this->offerData() + ['publish' => true]);

        $this->service()->pause($owner, $offer);
        $this->assertSame('paused', $this->signal($offer)->status);

        $this->service()->publish($owner, $offer->fresh());
        $this->assertSame('live', $this->signal($offer)->status);

        $this->service()->archive($owner, $offer->fresh());
        $this->assertSame('removed', $this->signal($offer)->status);

        $this->service()->publish($owner, $offer->fresh());
        $this->assertSame('live', $this->signal($offer)->status);

        $this->service()->delete($owner, $offer->fresh());
        $this->assertSame('removed', $this->signal($offer)->status);
        $this->assertSoftDeleted('pulse_offers', ['id' => $offer->id]);
    }

    public function test_updating_a_live_offer_resyncs_the_signal_and_rechecks_the_text(): void
    {
        $owner = $this->infodotPerson();
        $entity = $this->page($owner);
        $offer = $this->service()->create($owner, $entity, $this->offerData() + ['publish' => true]);

        $this->service()->update($owner, $offer, ['title' => 'Payroll and bookkeeping']);
        $this->assertSame('Payroll and bookkeeping', $this->signal($offer)->title);

        $this->rules(Fakes\HoldRules::class);
        $this->service()->update($owner, $offer->fresh(), ['description' => 'Visit my site now for a great deal on everything']);
        $this->assertSame('held', $offer->fresh()->moderation);
        $this->assertSame('held', $this->signal($offer)->status);
    }

    public function test_a_moderators_removal_cannot_be_undone_by_the_owner(): void
    {
        $owner = $this->infodotPerson();
        $offer = $this->service()->create($owner, $this->page($owner), $this->offerData() + ['publish' => true]);
        app(ListingModeration::class)->decide($this->moderator(), $offer, 'removed', 'scam');

        $this->assertSame('removed', $this->signal($offer)->status);

        $this->expectException(ValidationException::class);
        $this->service()->publish($owner, $offer->fresh());
    }

    public function test_moderation_changes_move_the_signal(): void
    {
        $owner = $this->person();
        $offer = $this->service()->create($owner, $this->page($owner), $this->offerData() + ['publish' => true]);
        $moderator = $this->moderator();
        $moderation = app(ListingModeration::class);

        $this->assertSame('held', $this->signal($offer)->status);
        $moderation->decide($moderator, $offer, 'approved');
        $this->assertSame('live', $this->signal($offer)->status);
        $moderation->decide($moderator, $offer->fresh(), 'held');
        $this->assertSame('held', $this->signal($offer)->status);
        $this->assertDatabaseHas('pulse_moderation_logs', ['moderator_id' => $moderator->id, 'action' => 'approve']);
    }

    public function test_only_moderators_decide(): void
    {
        $owner = $this->person();
        $offer = PulseOffer::factory()->create(['entity_id' => $this->page($owner)->id]);

        $this->expectException(HttpException::class);
        app(ListingModeration::class)->decide($owner, $offer, 'approved');
    }

    public function test_authorisation_for_stranger_responder_editor_admin_owner(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);

        foreach ([$this->person(), $this->member($entity, 'responder')] as $nobody) {
            try {
                $this->service()->create($nobody, $entity, $this->offerData());
                $this->fail('expected an authorisation failure');
            } catch (AuthorizationException) {
            }
        }

        foreach (['editor', 'admin'] as $role) {
            $offer = $this->service()->create($this->member($entity, $role), $entity, $this->offerData(['title' => "Offer by $role"]));
            $this->assertSame("Offer by $role", $offer->title);
        }

        $offer = $this->service()->create($owner, $entity, $this->offerData(['title' => 'Owner offer']));
        $this->assertSame($owner->id, $offer->created_by);

        foreach ([$this->person(), $this->member($entity, 'responder')] as $nobody) {
            foreach (['pause', 'publish', 'archive', 'delete'] as $method) {
                try {
                    $this->service()->$method($nobody, $offer);
                    $this->fail("$method must be refused");
                } catch (AuthorizationException) {
                }
            }

            try {
                $this->service()->update($nobody, $offer, ['title' => 'Hacked']);
                $this->fail('update must be refused');
            } catch (AuthorizationException) {
            }
        }
    }

    public function test_slugs_are_unique_per_page_not_globally(): void
    {
        $owner = $this->person();
        $a = $this->page($owner);
        $b = $this->page($owner);

        $one = $this->service()->create($owner, $a, $this->offerData(['title' => 'Same title']));
        $two = $this->service()->create($owner, $a, $this->offerData(['title' => 'Same title']));
        $three = $this->service()->create($owner, $b, $this->offerData(['title' => 'Same title']));

        $this->assertSame('same-title', $one->slug);
        $this->assertSame('same-title-2', $two->slug);
        $this->assertSame('same-title', $three->slug);
    }

    public function test_the_offers_per_page_quota(): void
    {
        config(['pulse.entities.max_offers' => 2]);
        $owner = $this->person();
        $entity = $this->page($owner);

        $first = $this->service()->create($owner, $entity, $this->offerData(['title' => 'First offer']));
        $this->service()->create($owner, $entity, $this->offerData(['title' => 'Second offer']));

        try {
            $this->service()->create($owner, $entity, $this->offerData(['title' => 'Third offer']));
            $this->fail('quota');
        } catch (ValidationException) {
        }

        $this->service()->archive($owner, $first); // archived offers do not count
        $this->service()->create($owner, $entity, $this->offerData(['title' => 'Third offer']));
        $this->assertSame(3, PulseOffer::where('entity_id', $entity->id)->count());
    }

    public function test_a_page_that_does_not_sell_cannot_have_offers(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner, ['is_seller' => false]);

        $this->expectException(ValidationException::class);
        $this->service()->create($owner, $entity, $this->offerData());
    }

    public function test_a_priced_offer_needs_a_price(): void
    {
        $owner = $this->person();

        $this->expectException(ValidationException::class);
        $this->service()->create($owner, $this->page($owner), $this->offerData(['price_mode' => 'fixed', 'amount_cents' => null]));
    }

    public function test_quote_offers_drop_any_amount(): void
    {
        $owner = $this->person();
        $offer = $this->service()->create($owner, $this->page($owner), $this->offerData(['price_mode' => 'quote', 'amount_cents' => 1000]));

        $this->assertNull($offer->amount_cents);
    }

    public function test_images_must_be_this_pages_offer_images(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $foreign = PulseMedia::create([
            'user_id' => $owner->id, 'purpose' => 'offer', 'entity_id' => $this->page()->id, 'disk' => 'local', 'mime' => 'image/webp',
            'width' => 10, 'height' => 10, 'bytes' => 1, 'variants' => [],
        ]);

        $this->expectException(ValidationException::class);
        $this->service()->create($owner, $entity, $this->offerData(['media_ids' => [$foreign->id]]));
    }

    public function test_text_is_sanitised_and_publishing_needs_a_description(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $offer = $this->service()->create($owner, $entity, ['title' => '<i>Plain</i> offer', 'price_mode' => 'quote']);

        $this->assertSame('Plain offer', $offer->title);

        $this->expectException(ValidationException::class);
        $this->service()->publish($owner, $offer);
    }

    public function test_a_blocked_text_is_not_saved_as_live(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $offer = $this->service()->create($owner, $entity, $this->offerData());
        $this->rules(Fakes\BlockRules::class);

        try {
            $this->service()->publish($owner, $offer);
            $this->fail('blocked');
        } catch (ValidationException) {
            $this->assertSame('draft', $offer->fresh()->status);
        }
    }

    public function test_a_page_that_stops_being_live_pauses_its_offers(): void
    {
        $owner = $this->infodotPerson();
        $entity = $this->page($owner);
        $offer = $this->service()->create($owner, $entity, $this->offerData() + ['publish' => true]);
        $this->assertSame('live', $this->signal($offer)->status);

        $entity->update(['moderation' => 'held']);
        $this->assertSame('held', $this->signal($offer)->status);

        $entity->forceFill(['moderation' => 'approved', 'status' => 'suspended'])->save();
        $this->assertSame('paused', $this->signal($offer)->status);

        $entity->update(['status' => 'live']);
        $this->assertSame('live', $this->signal($offer)->status);

        $entity->delete();
        $this->assertSame('removed', $this->signal($offer)->status);
    }

    public function test_route_keys_are_slugs(): void
    {
        $offer = PulseOffer::factory()->create();

        $this->assertSame('slug', $offer->getRouteKeyName());
        $this->assertSame('slug', $offer->entity->getRouteKeyName());
    }
}

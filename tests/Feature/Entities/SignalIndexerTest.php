<?php

namespace Tests\Feature\Entities;

use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Services\Pulse\Entities\SignalIndexer;

class SignalIndexerTest extends EntitiesTestCase
{
    private function indexer(): SignalIndexer
    {
        return app(SignalIndexer::class);
    }

    public function test_sync_is_idempotent(): void
    {
        $offer = PulseOffer::factory()->create();
        $first = PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->firstOrFail();

        $this->indexer()->syncSubject($offer->fresh());
        $this->indexer()->syncSubject($offer->fresh());

        $this->assertSame(1, PulseSignal::where('subject_type', 'offer')->count());
        $this->assertTrue($first->updated_at->equalTo(PulseSignal::find($first->id)->updated_at));
    }

    public function test_an_offer_signal_carries_title_topics_place_reach_and_owner(): void
    {
        $city = $this->place();
        $offer = PulseOffer::factory()->create(['title' => 'Mobile coffee cart', 'category' => 'Food & Drink', 'tags' => ['coffee', 'events'], 'place_id' => $city->id]);

        $signal = PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->firstOrFail();

        $this->assertSame('offer', $signal->kind);
        $this->assertSame('Mobile coffee cart', $signal->title);
        $this->assertSame(['food-drink', 'coffee', 'events'], $signal->topics);
        $this->assertSame($city->id, $signal->place_id);
        $this->assertSame('local', $signal->reach);
        $this->assertSame($offer->created_by, $signal->owner_id);
        $this->assertSame($offer->entity_id, $signal->entity_id);
        $this->assertSame('live', $signal->status);
    }

    public function test_service_area_sets_reach_and_remote(): void
    {
        $offer = PulseOffer::factory()->create(['service_area' => ['national', 'remote']]);

        $signal = PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->firstOrFail();

        $this->assertSame('national', $signal->reach);
        $this->assertTrue($signal->is_remote);
    }

    public function test_quality_is_completeness_and_never_engagement(): void
    {
        $bare = PulseOffer::factory()->create(['summary' => null, 'description' => 'short', 'tags' => [], 'category' => null, 'price_mode' => 'quote', 'amount_cents' => null, 'media_ids' => null]);
        $full = PulseOffer::factory()->create([
            'summary' => 'A summary', 'description' => str_repeat('x', 200), 'tags' => ['a'], 'category' => 'c',
            'price_mode' => 'fixed', 'amount_cents' => 100, 'place_id' => $this->place()->id, 'media_ids' => [1],
        ]);

        $q = fn (PulseOffer $o) => PulseSignal::where('subject_type', 'offer')->where('subject_id', $o->id)->value('quality');

        $this->assertSame(0.0, $q($bare));
        $this->assertSame(100.0, $q($full));

        $full->update(['enquiries_count' => 500, 'saves_count' => 900]); // engagement must not move it
        $this->assertSame(100.0, $q($full));
    }

    public function test_draft_offers_have_no_signal_and_trashed_ones_are_removed(): void
    {
        $draft = PulseOffer::factory()->draft()->create();
        $live = PulseOffer::factory()->create();

        $this->assertNull(PulseSignal::where('subject_type', 'offer')->where('subject_id', $draft->id)->first());

        $live->delete();
        $this->assertSame('removed', PulseSignal::where('subject_type', 'offer')->where('subject_id', $live->id)->value('status'));

        $live->restore();
        $this->assertSame('live', PulseSignal::where('subject_type', 'offer')->where('subject_id', $live->id)->value('status'));

        $live->forceDelete();
        $this->assertNull(PulseSignal::where('subject_type', 'offer')->where('subject_id', $live->id)->first());
    }

    public function test_an_expired_offer_is_expired(): void
    {
        $offer = PulseOffer::factory()->create(['expires_at' => now()->subDay()]);

        $this->assertSame('expired', PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->value('status'));
    }

    public function test_held_while_moderation_is_pending(): void
    {
        $offer = PulseOffer::factory()->pendingModeration()->create();
        $role = PulseRole::factory()->pendingModeration()->create();
        $need = PulseNeed::factory()->pendingModeration()->create();

        foreach ([['offer', $offer], ['role', $role], ['need', $need]] as [$type, $subject]) {
            $this->assertSame('held', PulseSignal::where('subject_type', $type)->where('subject_id', $subject->id)->value('status'), $type);
        }
    }

    public function test_entities_are_indexed_as_business_signals_only_while_live(): void
    {
        $entity = \App\Models\PulseEntity::factory()->create(['name' => 'Karoo Books']);
        $signal = PulseSignal::where('subject_type', 'entity')->where('subject_id', $entity->id)->firstOrFail();

        $this->assertSame('business', $signal->kind);
        $this->assertSame('Karoo Books', $signal->title);

        $entity->forceFill(['status' => 'draft'])->save();
        $this->assertSame('removed', $signal->fresh()->status);
    }

    public function test_hiring_pages_carry_their_wanted_skills(): void
    {
        $entity = \App\Models\PulseEntity::factory()->employer()->create(['hiring_signal' => 'building', 'hiring_skills' => ['Welding', 'Safety Rigging']]);

        $this->assertSame(['welding', 'safety-rigging'], PulseSignal::where('subject_type', 'entity')->where('subject_id', $entity->id)->value('skills'));
    }

    public function test_talent_signals_exist_only_for_discoverable_adults(): void
    {
        $user = $this->person();
        $profile = PulseProfile::factory()->create(['user_id' => $user->id]);
        $type = fn () => PulseSignal::where('subject_type', 'profile')->where('subject_id', $profile->id)->first();

        $this->assertNull($type());

        $profile->update(['discoverability' => 'open']); // no age confirmation
        $this->assertNull($type());

        $profile->update(['age_confirmed_at' => now()]);
        $this->assertSame('talent', $type()->kind);
        $this->assertSame('live', $type()->status);
        $this->assertFalse($type()->anonymous);

        $profile->update(['discoverability' => 'off']);
        $this->assertSame('removed', $type()->status);

        $profile->update(['discoverability' => 'anonymous']);
        $this->assertSame('live', $type()->status);
        $this->assertTrue($type()->anonymous);

        $profile->update(['moderation' => 'held']);
        $this->assertSame('held', $type()->status);
    }

    public function test_the_anonymous_signal_row_contains_nothing_that_names_the_person(): void
    {
        $city = $this->place('city', 'Sandton');
        $region = $this->place('region', 'Gauteng');
        $user = $this->person(['name' => 'Thandiwe Nkosi', 'email' => 'thandiwe.nkosi@example.org']);
        $profile = PulseProfile::factory()->anonymous()->create([
            'user_id' => $user->id,
            'headline' => 'Thandiwe Nkosi, senior accountant. Call +27 82 555 0147 or thandiwe.nkosi@example.org',
            'bio' => 'I am Thandiwe, see https://thandiwe.example/cv for my work',
            'skills' => ['IFRS', 'Audit', 'Payroll'],
            'place_id' => $city->id,
            'experience_years' => 8,
        ]);

        $signal = PulseSignal::where('subject_type', 'profile')->where('subject_id', $profile->id)->firstOrFail();
        $row = json_encode($signal->toArray());

        foreach (['Thandiwe', 'Nkosi', 'thandiwe', 'nkosi', 'example.org', '0147', 'https://', 'Sandton', '@'] as $leak) {
            $this->assertStringNotContainsString($leak, $row, "signal row leaks '$leak'");
        }

        $this->assertTrue($signal->anonymous);
        $this->assertSame($region->id, $signal->place_id, 'an anonymous person is only as precise as their region');
        $this->assertStringContainsString('5 to 9 years', $signal->excerpt);
        $this->assertStringContainsString('IFRS', $signal->excerpt);
        $this->assertSame(['ifrs', 'audit', 'payroll'], $signal->skills);
    }

    public function test_an_open_profile_hides_the_place_when_the_person_asked(): void
    {
        $profile = PulseProfile::factory()->discoverable('open')->create(['user_id' => $this->person()->id, 'place_id' => $this->place()->id, 'show_place' => false]);

        $this->assertNull(PulseSignal::where('subject_type', 'profile')->where('subject_id', $profile->id)->value('place_id'));
    }

    public function test_posts_enter_the_index_only_when_public_and_published(): void
    {
        $question = PulsePost::factory()->create(['type' => 'question', 'title' => 'How do I register for VAT?', 'body' => 'Turnover is near the threshold']);
        $chat = PulsePost::factory()->create(['type' => 'discussion']);
        $private = PulsePost::factory()->create(['type' => 'question', 'community_id' => $this->privateCommunity()->id]);
        $pending = PulsePost::factory()->status('pending')->create(['type' => 'question']);

        foreach ([$question, $chat, $private, $pending] as $post) {
            $this->indexer()->syncSubject($post);
        }

        $this->assertSame('ask', PulseSignal::where('subject_type', 'post')->where('subject_id', $question->id)->value('kind'));
        $this->assertSame(1, PulseSignal::where('subject_type', 'post')->where('status', 'live')->count());
    }

    private function privateCommunity()
    {
        return \App\Models\Community::factory()->private()->create();
    }

    public function test_rebuild_resyncs_everything_and_drops_orphans(): void
    {
        $offer = PulseOffer::factory()->create();
        $role = PulseRole::factory()->create();
        PulseSignal::query()->delete();
        PulseSignal::factory()->create(['subject_type' => 'offer', 'subject_id' => 987654]); // its subject no longer exists

        $count = $this->indexer()->rebuild();

        $this->assertGreaterThanOrEqual(4, $count); // two pages, an offer, a role (plus factory-made extras)
        $this->assertNotNull(PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->first());
        $this->assertNotNull(PulseSignal::where('subject_type', 'role')->where('subject_id', $role->id)->first());
        $this->assertNull(PulseSignal::where('subject_id', 987654)->first());
    }

    public function test_unknown_models_are_ignored(): void
    {
        $this->assertNull($this->indexer()->syncSubject($this->person()));
    }
}

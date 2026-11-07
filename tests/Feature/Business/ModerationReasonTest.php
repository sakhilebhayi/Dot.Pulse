<?php

namespace Tests\Feature\Business;

use App\Livewire\Pulse\Business\OfferManager;
use App\Livewire\Pulse\Business\RoleManager;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use App\Services\Pulse\Entities\ListingModeration;
use App\Services\Pulse\Entities\OfferService;
use App\Services\Pulse\Entities\RoleService;
use App\Services\Pulse\Operator\ModerationDesk;
use Livewire\Livewire;

class ModerationReasonTest extends BusinessTestCase
{
    private function offer($owner, $page): PulseOffer
    {
        return app(OfferService::class)->create($owner, $page, [
            'title' => 'Bulk tiles', 'summary' => 'Sale at https://a.example.com and https://b.example.com now', 'category' => 'retail',
        ]);
    }

    public function test_a_held_offer_stores_why_and_shows_it_in_the_manage_view(): void
    {
        config(['pulse.entities.rules_class' => null]); // the real rules
        $page = $this->page();
        $owner = $page->members()->first()->user;

        $offer = app(OfferService::class)->publish($owner, $this->offer($owner, $page));

        $this->assertSame('held', $offer->moderation);
        $this->assertNotEmpty($offer->moderation_reason);
        $this->assertSame('link_density', $offer->moderation_flags[0]['code']);

        Livewire::actingAs($owner)->test(OfferManager::class, ['entity' => $page])
            ->assertSee('Waiting for a human: '.$offer->moderation_reason);
    }

    public function test_the_moderation_queue_reads_the_stored_flags_and_approval_clears_the_reason(): void
    {
        config(['pulse.entities.rules_class' => null]);
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $offer = app(OfferService::class)->publish($owner, $this->offer($owner, $page));
        $offer->forceFill(['moderation_flags' => [['code' => 'stored_only', 'severity' => 'hold', 'text' => 'Stored flag text']]])->save();

        $why = app(ModerationDesk::class)->why($offer->refresh(), $owner);
        $this->assertSame('stored_only', $why['flags'][0]['code']);

        app(ListingModeration::class)->decide($this->moderator(), $offer, 'approved');
        $offer->refresh();
        $this->assertNull($offer->moderation_reason);
        $this->assertNull($offer->moderation_flags);
    }

    public function test_no_rules_gives_the_pending_reason_and_a_moderator_removal_keeps_the_rationale(): void
    {
        $page = $this->page($this->person());
        $owner = $page->members()->first()->user;
        $role = app(RoleService::class)->create($owner, $page, [
            'title' => 'Bookkeeper', 'summary' => 'Keep our books.', 'employment_type' => 'full_time', 'publish' => true,
        ]);

        $this->assertSame('pending', $role->moderation);
        $this->assertStringContainsString('No automatic check ran', $role->moderation_reason);

        Livewire::actingAs($owner)->test(RoleManager::class, ['entity' => $page])->assertSee('Waiting for a human: No automatic check ran');

        app(ListingModeration::class)->decide($this->moderator(), $role, 'removed', 'Not a real role');
        $this->assertSame('Not a real role', PulseRole::find($role->id)->moderation_reason);
    }
}

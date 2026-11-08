<?php

namespace Tests\Feature\Discovery;

use App\Models\PulseDoor;
use App\Models\PulseOffer;
use App\Services\Pulse\Discovery\Floor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class BeatUrlTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    public function test_a_beat_links_to_the_specific_page_where_one_exists_and_to_the_signal_page_otherwise(): void
    {
        $geo = $this->dGeo();
        $me = $this->dUser(['place' => $geo->jhb]);
        $owner = $this->dUser();
        $entity = $this->dEntity($owner, ['name' => 'Karoo Books']);

        $offerSignal = $this->dOfferSignal($entity, ['bookkeeping']);
        $offer = PulseOffer::find($offerSignal->subject_id);

        $role = $this->dRole($entity, ['title' => 'Junior bookkeeper']);
        $roleSignal = $this->dSignal(['kind' => 'role', 'subject_type' => 'role', 'subject_id' => $role->id, 'entity_id' => $entity->id, 'owner_id' => $owner->id, 'title' => $role->title]);

        $need = $this->dNeed($owner);
        $needSignal = $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'subject_id' => $need->id, 'owner_id' => $owner->id, 'title' => $need->title]);

        $news = $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'subject_id' => 999999, 'owner_id' => $owner->id]);

        $door = PulseDoor::query()->create(['owner_id' => $owner->id, 'opened_by' => $owner->id, 'purpose' => 'Meet a plumber', 'length' => 'day', 'status' => 'open', 'opens_at' => now(), 'closes_at' => now()->addDay()]);
        $doorSignal = $this->dSignal(['kind' => 'door', 'subject_type' => $door->getMorphClass(), 'subject_id' => $door->id, 'owner_id' => $owner->id, 'title' => 'Meet a plumber']);

        foreach ([$offerSignal, $roleSignal, $needSignal, $news, $doorSignal] as $s) {
            $this->dMatch($me, $s, 50);
        }

        $beats = collect(app(Floor::class)->round($me)['beats'])->keyBy(fn ($b) => $b->signal->id);

        $this->assertSame(route('offers.show', ['entity' => $entity->slug, 'offer' => $offer->slug]), $beats[$offerSignal->id]->url);
        $this->assertSame(route('roles.show', ['entity' => $entity->slug, 'role' => $role->slug]), $beats[$roleSignal->id]->url, 'roles are addressed by slug, not id');
        $this->assertSame(route('needs.show', ['need' => $need->id]), $beats[$needSignal->id]->url);
        $this->assertSame(route('signals.show', ['signal' => $news->id]), $beats[$news->id]->url, 'a post that cannot be loaded falls back to the signal page');
        $this->assertSame(route('signals.show', ['signal' => $doorSignal->id]), $beats[$doorSignal->id]->url, 'a door has a page too');
    }

    public function test_the_kind_word_knows_doors(): void
    {
        $this->assertStringContainsString('>Door<', Blade::render('<x-pulse.kind-word kind="door" />'));
    }
}

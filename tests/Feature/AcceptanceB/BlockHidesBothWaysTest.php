<?php

namespace Tests\Feature\AcceptanceB;

use App\Models\PulseEntityBlock;
use App\Models\PulseNeed;
use App\Models\User;
use App\Services\Pulse\Discovery\Digest\DigestBuilder;
use App\Services\Pulse\Discovery\Floor;
use App\Services\Pulse\Discovery\SearchService;
use App\Services\Pulse\Lines\BlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Floor\FloorTestCase;

/**
 * Found by the browser acceptance run (suite B, criterion 10): blocking a business page closed the lines and wrote the
 * block, but the page stayed on the Floor, in search, in the digest and reachable by its address. Hidden both ways
 * means all of those, for the blocker and for the page.
 */
class BlockHidesBothWaysTest extends FloorTestCase
{
    use RefreshDatabase;

    private function titles(array $beats): array
    {
        return collect($beats)->pluck('title')->all();
    }

    public function test_a_blocked_page_leaves_the_floor_search_suggestions_and_digest(): void
    {
        [$signal, $entity] = $this->offerFor('Geyser repair and replacement');
        $this->dPrefs($this->me);
        $signal->forceFill(['published_at' => now()->subHour()])->save();

        $beats = fn () => $this->titles(app(Floor::class)->round($this->me, 'all')['beats']);
        $found = fn () => $this->titles(app(SearchService::class)->search($this->me, 'geyser')['beats'] ?? app(SearchService::class)->search($this->me, 'geyser')['results'] ?? []);

        $this->assertContains('Geyser repair and replacement', $beats());
        $this->assertNotNull(app(DigestBuilder::class)->build($this->me));

        app(BlockService::class)->block($this->me, $entity);

        $this->assertNotContains('Geyser repair and replacement', $beats());
        $this->assertNull(app(DigestBuilder::class)->build($this->me), 'nothing real is left, so no digest');
        $suggest = collect(app(SearchService::class)->suggest($this->me, substr($entity->name, 0, 6)))->pluck('label')->all();
        $this->assertNotContains($entity->name, $suggest);
        $this->assertStringNotContainsString('Geyser repair', json_encode(app(SearchService::class)->search($this->me, 'geyser')));
    }

    public function test_a_blocked_page_is_a_404_for_the_blocker_but_not_for_its_own_people_or_moderators(): void
    {
        [, $entity, , $owner] = $this->offerFor();
        $entity->forceFill(['moderation' => 'approved'])->save();
        app(BlockService::class)->block($this->me, $entity);

        $this->assertFalse(Gate::forUser($this->me)->allows('view', $entity));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $entity));
        $this->actingAs($this->me)->get(route('entities.show', $entity->slug))->assertNotFound();

        $other = $this->person();
        $this->assertTrue(Gate::forUser($other)->allows('view', $entity), 'blocking is personal: everyone else still sees the page');
    }

    public function test_a_page_that_blocks_a_person_disappears_for_them_and_their_needs_disappear_for_the_page(): void
    {
        [$signal, $entity, , $owner] = $this->offerFor('Geyser repair and replacement');
        app(BlockService::class)->blockAsEntity($owner, $entity, $this->me);

        $this->assertTrue(PulseEntityBlock::where('entity_id', $entity->id)->where('user_id', $this->me->id)->exists());
        $this->assertNotContains('Geyser repair and replacement', $this->titles(app(Floor::class)->round($this->me, 'all')['beats']));
        $this->assertFalse(Gate::forUser($this->me)->allows('view', $entity));

        // and the page's people never see this person's need
        $need = $this->dNeed($this->me, ['title' => 'Need a geyser']);
        $needSignal = $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'subject_id' => $need->id, 'owner_id' => $this->me->id, 'title' => 'Need a geyser']);
        $this->dMatch($owner, $needSignal, 80);
        $this->assertNotContains('Need a geyser', $this->titles(app(Floor::class)->round($owner, 'all')['beats']));
    }

    public function test_a_person_blocked_either_way_cannot_open_the_need_or_the_page_of_the_other(): void
    {
        $asker = $this->person();
        $need = PulseNeed::query()->find($this->dNeed($asker, ['title' => 'Need a bookkeeper'])->id);
        $this->assertTrue(Gate::forUser($this->me)->allows('view', $need));

        app(BlockService::class)->block($asker, $this->me);
        $this->assertFalse(Gate::forUser($this->me)->allows('view', $need->fresh()), 'the blocked person');
        $this->assertTrue(Gate::forUser($asker)->allows('view', $need->fresh()));

        [, $entity, , $owner] = $this->offerFor();
        $entity->forceFill(['moderation' => 'approved'])->save();
        app(BlockService::class)->block($owner, $asker);
        $this->assertFalse(Gate::forUser($asker)->allows('view', $entity), 'a person the page owner blocked cannot open the page');
        $this->assertInstanceOf(User::class, $owner);
    }
}

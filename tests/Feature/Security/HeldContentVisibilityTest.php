<?php

namespace Tests\Feature\Security;

use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseSignal;
use App\Services\Pulse\Discovery\Digest\DigestBuilder;
use App\Services\Pulse\Discovery\Floor;
use App\Services\Pulse\Discovery\SearchService;
use App\Services\Pulse\Entities\SignalIndexer;
use App\Services\Pulse\Lines\ContactGate;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Operator\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Held, draft, removed, suspended and archived things are invisible to everyone but the people who run them and the
 * moderators: Floor, search, detail pages, the digest, contact and the numbers on the operator desk.
 */
class HeldContentVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use SecurityWorld;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->buildWorld();
    }

    /** @return list<array{string, \Closure}> label => mutate the seller page so it should vanish */
    private function pageStates(): array
    {
        return [
            ['suspended', fn ($e) => $e->forceFill(['status' => 'suspended'])->save()],
            ['archived', fn ($e) => $e->forceFill(['status' => 'archived'])->save()],
            ['removed', fn ($e) => $e->forceFill(['moderation' => 'removed'])->save()],
            ['held', fn ($e) => $e->forceFill(['moderation' => 'pending'])->save()],
        ];
    }

    private function signalFor(object $subject): PulseSignal
    {
        app(SignalIndexer::class)->syncSubject($subject->fresh());

        return PulseSignal::query()->where('subject_type', 'offer')->where('subject_id', $subject->id)->firstOrFail();
    }

    private function seen(PulseSignal $signal): array
    {
        $viewer = $this->w('stranger');
        PulseMatch::query()->updateOrCreate(['user_id' => $viewer->id, 'signal_id' => $signal->id], ['score' => 90, 'reasons' => [['code' => 'topic', 'text' => 'Plumbing', 'weight' => 40, 'ref' => 'plumbing']], 'computed_at' => now(), 'dismissed_at' => null, 'seen_at' => null]);

        $onFloor = collect(app(Floor::class)->round($viewer, 'all')['beats'])->contains(fn ($b) => $b->signal->id === $signal->id);
        $inSearch = collect(app(SearchService::class)->search($viewer, $signal->title)['results'])->contains(fn ($r) => ($r['beat']->signal->id ?? null) === $signal->id);
        $detail = $this->actingAs($viewer)->get('/s/'.$signal->id)->getStatusCode();
        $digest = json_encode(app(DigestBuilder::class)->build($viewer));

        return [$onFloor, $inSearch, $detail, str_contains((string) $digest, $signal->title)];
    }

    public function test_a_held_draft_or_removed_listing_is_nowhere_a_stranger_can_look(): void
    {
        $seller = $this->w('seller');

        // A control: the live offer is findable everywhere.
        $live = $this->signalFor($this->w('offer'));
        $this->assertSame([true, true, 200, true], $this->seen($live), 'the live control must be visible or this test proves nothing');

        foreach ([['pending', ['moderation' => 'pending']], ['draft', ['status' => 'draft']], ['paused', ['status' => 'paused']], ['archived', ['status' => 'archived']]] as [$label, $change]) {
            $offer = $this->offer($seller, ['title' => 'Hidden '.$label.' offer text']);
            $offer->forceFill($change)->save();
            $signal = $this->signalFor($offer);

            $this->assertNotSame('live', $signal->status, "{$label} offer's signal must not be live");
            $this->assertSame([false, false, 404, false], $this->seen($signal), "{$label} offer is visible");
        }
    }

    public static function states(): array
    {
        return [['suspended'], ['archived'], ['removed'], ['held']];
    }

    #[DataProvider('states')]
    public function test_the_page_state_hides_its_listings_and_stops_contact(string $state): void
    {
        $seller = $this->w('seller');
        $signal = $this->signalFor($this->w('offer'));
        $this->assertSame([true, true, 200, true], $this->seen($signal));

        collect($this->pageStates())->firstWhere(0, $state)[1]($seller);
        app(SignalIndexer::class)->syncSubject($seller->fresh());
        app(SignalIndexer::class)->syncSubject($this->w('offer')->fresh());

        $this->assertSame([false, false, 404, false], $this->seen($signal->fresh()), "{$state} page still shows its offer");

        // And the page itself is not found by its name, nor suggested while typing it.
        $byName = collect(app(SearchService::class)->search($this->w('stranger'), 'Moyo Plumbing')['results'])->filter(fn ($r) => ($r['entity']['slug'] ?? null) === $seller->slug || ($r['beat']->signal->entity_id ?? null) === $seller->id);
        $this->assertCount(0, $byName, "{$state} page is still found by name");
        $this->assertSame([], collect(app(SearchService::class)->suggest($this->w('stranger'), 'Moyo Plum'))->where('type', 'business')->all());
        $this->actingAs($this->w('stranger'))->get('/b/'.$seller->slug)->assertNotFound();
        $this->actingAs($this->w('stranger'))->get('/b/'.$seller->slug.'/o/'.$this->w('offer')->slug)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get('/b/'.$seller->slug)->assertNotFound();

        // Nobody can start a conversation with it, even through a context they hold.
        $this->assertFalse(app(ContactGate::class)->can($this->w('needOwner'), $seller->fresh())['allowed'], "{$state} page can still be contacted");
        try {
            app(LineService::class)->knock($this->w('needOwner'), ['entity' => $seller->id], 'enquiry', $this->card(), $this->w('offer')->fresh());
            $this->fail("{$state} page accepted a knock");
        } catch (LineException) {
            $this->assertTrue(true);
        }
    }

    public function test_the_operator_numbers_count_only_what_is_really_live(): void
    {
        $metrics = app(Metrics::class);
        $before = $metrics->liveListings();
        $seller = $this->w('seller');

        $seller->forceFill(['status' => 'suspended'])->save();

        $this->assertLessThan($before['offers']['total'], $metrics->liveListings()['offers']['total'], 'offers of a suspended page still count as live');
        $this->assertLessThan($before['roles']['total'], $metrics->liveListings()['roles']['total'], 'roles of a suspended page still count as live');
        $this->assertSame(0, $metrics->liveListings()['offers']['total'] ?? 0);
    }

    public function test_a_held_need_and_a_held_role_never_leave_their_owners(): void
    {
        $viewer = $this->w('stranger');
        $need = $this->w('heldNeed');
        $role = $this->w('heldRole');

        foreach ([$need, $role] as $subject) {
            app(SignalIndexer::class)->syncSubject($subject->fresh());
            $type = $subject instanceof PulseNeed ? 'need' : 'role';
            $signal = PulseSignal::query()->where('subject_type', $type)->where('subject_id', $subject->id)->first();
            $this->assertTrue($signal === null || $signal->status !== 'live');
        }

        $this->actingAs($viewer)->get('/need/'.$need->id)->assertNotFound();
        $this->actingAs($viewer)->get('/b/'.$this->w('seller')->slug.'/r/'.$role->slug)->assertNotFound();
    }
}

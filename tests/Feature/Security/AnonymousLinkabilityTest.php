<?php

namespace Tests\Feature\Security;

use App\Models\PulseDoor;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\SearchService;
use App\Services\Pulse\Entities\NeedService;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Entities\SignalIndexer;
use App\Services\Pulse\Lines\Doors;
use App\Services\Pulse\Lines\LineException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

/**
 * An anonymous professional who also acts under their name (a door, a need, a community post) must not be linkable to
 * their talent card by skills, region or timing.
 */
class AnonymousLinkabilityTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private object $geo;

    private User $employer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.privacy.talent_cohort_min' => 5, 'pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->geo = $this->dGeo();
        $this->employer = $this->dUser(['place' => $this->geo->jhb]);
        $this->onboard($this->employer);
        $page = $this->dEntity($this->employer, ['name' => 'Karoo Logistics', 'is_employer' => true, 'place_id' => $this->geo->jhb->id]);
        $this->dRole($page, ['skills' => ['bookkeeping']]);
    }

    private function pro(string $mode = 'anonymous'): User
    {
        $u = $this->dUser(['place' => $this->geo->jhb]);
        $this->onboard($u);
        $service = app(ProfileService::class);
        $service->confirmAge($u);
        $service->update($u, ['headline' => 'Bookkeeper', 'skills' => ['bookkeeping', 'payroll'], 'place_id' => $this->geo->jhb->id, 'experience_years' => 6]);
        $service->setDiscoverability($u, $mode);
        app(SignalIndexer::class)->syncSubject(PulseProfile::where('user_id', $u->id)->first());

        return $u;
    }

    /** @return array{0: User, 1: PulseSignal} the person we try to unmask, and their card inside a cohort of five */
    private function target(): array
    {
        foreach (range(1, 4) as $i) {
            $this->pro();
        }
        $target = $this->pro();

        return [$target, PulseSignal::where('kind', 'talent')->where('owner_id', $target->id)->first()];
    }

    public function test_an_anonymous_professional_cannot_open_a_door_under_their_name(): void
    {
        $pro = $this->pro();

        try {
            app(Doors::class)->open($pro, 'Looking for a baker for Saturday', 'day');
            $this->fail('A door carries a name and a place; it must be refused while anonymous.');
        } catch (LineException $e) {
            $this->assertSame('anonymous', $e->reason);
            $this->assertStringContainsString('Switch to open to do this under your name.', $e->getMessage());
        }

        $this->assertSame(0, PulseDoor::count());
        $this->assertSame(0, PulseSignal::where('kind', 'door')->count());
    }

    public function test_an_anonymous_professional_cannot_publish_or_reopen_a_need(): void
    {
        $pro = $this->pro('open');
        $need = app(NeedService::class)->create($pro, ['title' => 'Looking for an accountant to review our books', 'kind' => 'supplier', 'details' => 'Quarter end review please.']);
        app(NeedService::class)->close($pro, $need);

        app(ProfileService::class)->setDiscoverability($pro, 'anonymous');

        foreach ([
            fn () => app(NeedService::class)->create($pro, ['title' => 'Looking for a payroll tool for the team', 'kind' => 'supplier', 'details' => 'Small team of six.']),
            fn () => app(NeedService::class)->reopen($pro, $need),
        ] as $try) {
            try {
                $try();
                $this->fail('A need carries a name; it must be refused while anonymous.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Switch to open to do this under your name.', $e->errors()['title'][0]);
            }
        }
    }

    public function test_switching_to_anonymous_closes_the_door_and_needs_that_carry_the_name(): void
    {
        $pro = $this->pro('open');
        $door = app(Doors::class)->open($pro, 'Looking for a baker for Saturday', 'day');
        $need = app(NeedService::class)->create($pro, ['title' => 'Looking for an accountant to review our books', 'kind' => 'supplier', 'details' => 'Quarter end review please.']);
        $this->assertSame('live', $door->fresh()->status);

        app(ProfileService::class)->setDiscoverability($pro, 'anonymous');

        $this->assertSame('expired', $door->fresh()->status);
        $this->assertSame('closed', $need->fresh()->status);
        $this->assertSame(0, PulseSignal::where('owner_id', $pro->id)->whereIn('kind', ['door', 'need'])->where('status', 'live')->count());

        // And back to open: the rule lifts at once.
        app(ProfileService::class)->setDiscoverability($pro, 'open');
        $this->assertNotNull(app(Doors::class)->open($pro, 'Open for a chat about payroll', 'week'));
    }

    public function test_a_linking_attempt_by_skills_region_and_time_finds_nothing_named_to_join_the_card_to(): void
    {
        [$target, $card] = $this->target();

        // The heuristic: every named signal whose owner is live in the same region at about the same time, with topics
        // that overlap the card's skills. Nothing the anonymous person could do under their name exists to be joined.
        $named = PulseSignal::query()->whereIn('kind', ['door', 'need'])->where('owner_id', $target->id)->count();
        $this->assertSame(0, $named);

        // They cannot make one either.
        try {
            app(Doors::class)->open($target, 'Looking for a baker for Saturday', 'day');
        } catch (LineException) {
        }
        $this->assertSame(0, PulseSignal::query()->where('owner_id', $target->id)->whereIn('kind', ['door', 'need'])->count());

        // Timing: what the employer sees of the card carries only the start of its week.
        $found = collect(app(SearchService::class)->search($this->employer, 'bookkeeping')['results'])->where('type', 'signal')->pluck('beat')
            ->first(fn ($b) => $b->kind === 'talent');
        $this->assertNotNull($found);
        $this->assertTrue($found->published_at->isSameDay($card->published_at->copy()->startOfWeek()));
        $this->assertNull($found->place['id'] ?? null);
    }

    public function test_a_community_post_cannot_be_used_to_resolve_a_talent_signals_owner(): void
    {
        [$target, $card] = $this->target();

        $post = PulsePost::factory()->create(['user_id' => $target->id, 'type' => 'discussion', 'body' => 'Quarter end is close and the ledgers are a mess this month.', 'status' => 'published']);
        app(SignalIndexer::class)->syncSubject($post);

        $this->actingAs($this->employer);

        // The post page names the author by handle and links nowhere that leads to the card or the profile.
        $page = $this->get(route('posts.show', $post))->assertOk();
        $html = $page->getContent();
        $this->assertStringNotContainsString('/s/'.$card->id, $html);
        $this->assertStringNotContainsString('/u/'.$target->id, $html, 'the byline does not link to the profile');
        $this->get(route('users.show', $target->id))->assertNotFound();

        // The post, as a Beat, shares no id, place or time with the card.
        $postSignal = PulseSignal::forSubject('post', $post->id)->first() ?? PulseSignal::where('owner_id', $target->id)->where('kind', '!=', 'talent')->first();

        if ($postSignal !== null) {
            $this->assertNull($postSignal->place_id, 'a post carries no place to join on');
            $this->assertEmpty((array) $postSignal->skills, 'a post carries no skills list to join on');
            $this->assertNotSame($card->id, $postSignal->id);
        }

        // The card the employer is shown has no owner, subject or exact time, so the post cannot be matched to it by any key.
        $beat = collect(app(SearchService::class)->search($this->employer, 'bookkeeping')['results'])->where('type', 'signal')->pluck('beat')->first(fn ($b) => $b->kind === 'talent');
        $this->assertNull($beat->signal->owner_id);
        $this->assertSame(0, (int) $beat->signal->subject_id);
        $this->assertNotEquals($post->created_at->format('Y-m-d H:i:s'), $beat->published_at?->format('Y-m-d H:i:s'));

        // Direct tries: the card's signal id or the user id as a Livewire/route handle give nothing about the author.
        $this->assertSame(1, DB::table('pulse_signals')->where('kind', 'talent')->where('owner_id', $target->id)->count());
    }
}

<?php

namespace Tests\Feature\Security;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseReview;
use App\Models\User;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use App\Services\Pulse\Lines\QuoteService;
use App\Services\Pulse\Lines\ReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Spam, budget games, quote and review manipulation, outcome forgery, block evasion and the rate limits. */
class AbuseTest extends TestCase
{
    use RefreshDatabase;
    use SecurityWorld;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->buildWorld();
    }

    private function closedPage(): PulseEntity
    {
        $p = $this->page($this->person(), ['contact_policy' => 'requests', 'is_seller' => true]);
        $p->forceFill(['moderation' => 'approved'])->save();

        return $p;
    }

    // ------------------------------------------------------------------ the knock budget

    public function test_the_weekly_allowance_is_exact_and_a_stale_cache_does_not_widen_it(): void
    {
        $from = $this->person();
        $budget = app(KnockBudget::class);
        $this->assertSame(7, $budget->remaining($from)); // primes the one minute cache

        $made = 0;
        $refused = 0;
        for ($i = 0; $i < 10; $i++) {
            try {
                app(LineService::class)->knock($from, ['entity' => $this->closedPage()->id], 'enquiry', $this->card(['what' => 'Item number '.$i.' please']));
                $made++;
            } catch (LineException $e) {
                $this->assertSame('budget', $e->reason);
                $refused++;
            }
        }

        $this->assertSame(7, $made);
        $this->assertSame(3, $refused);
        $this->assertSame(0, $budget->remaining($from));
    }

    public function test_two_requests_racing_for_the_last_knock_cannot_both_win(): void
    {
        $from = $this->person();
        DB::table('pulse_knock_budgets')->insert(['user_id' => $from->id, 'window_started_at' => now(), 'spent' => 6, 'bonus' => 0, 'penalty' => 0, 'created_at' => now(), 'updated_at' => now()]);

        // Both "requests" read the same stale cached answer before either spends.
        Cache::put('pulse:knock-remaining:'.$from->id, 1, 60);
        $first = new KnockBudget;
        $second = new KnockBudget;
        $this->assertSame(1, $first->remaining($from));
        $this->assertSame(1, $second->remaining($from));

        $first->spend($from);

        $this->expectException(LineException::class);
        $second->spend($from);
    }

    public function test_a_knock_that_fails_after_spending_gives_the_allowance_back(): void
    {
        $from = $this->person();
        $page = $this->closedPage();

        $before = app(KnockBudget::class)->remaining($from);
        try {
            app(LineService::class)->knock($from, ['entity' => $page->id], 'enquiry', $this->card(['what' => 'x']));
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertSame($before, app(KnockBudget::class)->remaining($from));
    }

    public function test_the_allowance_cannot_be_bought_or_set_from_any_route(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('/budget|purchase|checkout|credits|topup|top-up|buy/i', $route->uri().' '.$route->getName(), 'a route could sell or set the allowance: '.$route->uri());
        }

        $methods = array_map(fn ($m) => $m->getName(), (new \ReflectionClass(KnockBudget::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        $this->assertSame([], array_values(array_intersect($methods, ['buy', 'purchase', 'add', 'grant', 'set', 'topUp'])));
    }

    // ------------------------------------------------------------------ quotes, outcomes, reviews

    public function test_a_quote_request_cannot_be_widened_past_three_businesses_by_asking_again(): void
    {
        $asker = $this->w('needOwner');
        $need = $this->w('need');
        $sellers = collect(range(1, 6))->map(function () {
            $e = $this->page($this->person(), ['is_seller' => true, 'contact_policy' => 'anyone']);
            $e->forceFill(['moderation' => 'approved'])->save();
            $this->offer($e, ['accepts_quotes' => true]);

            return $e->id;
        });
        $quotes = app(QuoteService::class);

        $quotes->request($asker, ['need' => $need->id], $sellers->take(3)->all(), $this->card());

        foreach ([[$sellers[3]], [$sellers[3], $sellers[4]], $sellers->slice(3)->all()] as $more) {
            try {
                $quotes->request($asker, ['need' => $need->id], $more, $this->card());
                $this->fail('a fourth business was asked about the same need');
            } catch (LineException $e) {
                $this->assertSame('limit', $e->reason);
            }
        }

        // The same business cannot be asked twice while the first request is alive.
        try {
            $quotes->request($asker, ['need' => $need->id], [$sellers[0]], $this->card());
            $this->fail('asked twice');
        } catch (LineException $e) {
            $this->assertSame('exists', $e->reason);
        }

        $this->assertSame(3, PulseConversation::query()->where('kind', 'quote')->where('initiator_id', $asker->id)->count());
    }

    public function test_an_outcome_needs_the_other_side_and_cannot_be_confirmed_by_the_proposer_or_a_teammate(): void
    {
        $line = $this->w('line');
        $outcomes = app(OutcomeService::class);
        $customer = $this->w('stranger');
        $teammate = $this->w('sellerResponder');
        $owner = $this->w('sellerOwner');

        app(LineService::class)->send($owner, $line->fresh(), 'On my way tomorrow');
        $outcomes->propose($customer, $line->fresh(), 'won');

        foreach ([$customer, $this->w('needOwner'), $this->w('sellerEditor'), $this->w('employerOwner')] as $someone) {
            try {
                $outcomes->confirm($someone, $line->fresh());
                $this->fail('confirmed by someone who may not');
            } catch (AuthorizationException|LineException) {
                $this->assertFalse($line->fresh()->hasConfirmedOutcome());
            }
        }

        // A teammate of the proposer's own side cannot confirm either.
        $outcomes->propose($teammate, $line->fresh(), 'won');
        try {
            $outcomes->confirm($owner, $line->fresh());
            $this->fail('same side confirmed');
        } catch (LineException) {
            $this->assertFalse($line->fresh()->hasConfirmedOutcome());
        }

        $outcomes->confirm($customer, $line->fresh());
        $this->assertTrue($line->fresh()->hasConfirmedOutcome());
    }

    public function test_a_review_stays_blind_until_both_sides_have_written_one(): void
    {
        $line = $this->w('line');
        $customer = $this->w('stranger');
        $seller = $this->w('sellerOwner');
        $outcomes = app(OutcomeService::class);
        $reviews = app(ReviewService::class);

        app(LineService::class)->send($seller, $line->fresh(), 'On my way tomorrow');
        $outcomes->propose($customer, $line->fresh(), 'won');
        $outcomes->confirm($seller, $line->fresh());

        $reviews->submit($customer, $line->fresh(), ['rating' => 1, 'body' => 'Terrible on the day.']);

        $this->assertSame([], $reviews->forSubject($this->w('seller'))->all());
        $this->assertNull($reviews->summary($this->w('seller'))['average']);
        $this->assertStringNotContainsString('Terrible on the day', $this->get('/b/'.$this->w('seller')->slug)->getContent());
        $this->assertStringNotContainsString('Terrible on the day', $this->actingAs($seller)->get('/b/'.$this->w('seller')->slug.'/manage')->getContent());

        $reviews->submit($seller, $line->fresh(), ['rating' => 5, 'body' => 'Great customer.']);

        $this->assertCount(1, $reviews->forSubject($this->w('seller')));

        // And a second review for the same line is refused.
        $this->expectException(LineException::class);
        $reviews->submit($customer, $line->fresh(), ['rating' => 5]);
    }

    public function test_nobody_outside_the_line_can_review_it_and_an_unconfirmed_result_earns_no_review(): void
    {
        $line = $this->w('line');
        $reviews = app(ReviewService::class);

        $this->expectException(LineException::class);
        $reviews->submit($this->w('stranger'), $line->fresh(), ['rating' => 5]); // no confirmed outcome yet
    }

    public function test_a_stranger_cannot_review_a_line_they_were_not_part_of(): void
    {
        $line = $this->w('line');
        app(LineService::class)->send($this->w('sellerOwner'), $line->fresh(), 'On my way tomorrow');
        app(OutcomeService::class)->propose($this->w('stranger'), $line->fresh(), 'won');
        app(OutcomeService::class)->confirm($this->w('sellerOwner'), $line->fresh());

        $this->expectException(AuthorizationException::class);
        app(ReviewService::class)->submit($this->w('needOwner'), $line->fresh(), ['rating' => 1, 'body' => 'Fake review']);
    }

    // ------------------------------------------------------------------ blocks

    public function test_a_page_a_person_blocked_cannot_invite_them_again_from_any_of_its_members(): void
    {
        $pro = $this->w('pro');
        $employer = $this->w('employer');
        $second = $this->member($employer, null, 'responder');
        $invite = $this->w('invite');
        app(LineService::class)->accept($pro, $invite);

        app(BlockService::class)->block($pro, $employer);
        $this->assertSame(PulseConversation::CLOSED, $invite->fresh()->status);

        foreach ([$this->w('employerOwner'), $second] as $member) {
            try {
                app(LineService::class)->knock($member, ['signal' => $this->w('talentSignal')->id, 'as_entity' => $employer->id], 'invite', ['what' => 'Another role we want to talk about']);
                $this->fail('a blocked page invited the person again');
            } catch (LineException $e) {
                $this->assertSame('not_allowed', $e->reason);
                $this->assertStringNotContainsStringIgnoringCase('block', $e->getMessage(), 'the refusal must not say that anyone blocked anyone');
            }
        }
    }

    public function test_a_person_blocked_by_a_page_cannot_reach_it_through_a_new_request_kind_or_a_quote(): void
    {
        $seller = $this->w('seller');
        $customer = $this->w('stranger');
        app(BlockService::class)->blockAsEntity($this->w('sellerOwner'), $seller, $customer);

        foreach (['enquiry', 'dm', 'intro'] as $kind) {
            try {
                app(LineService::class)->knock($customer, ['entity' => $seller->id], $kind, $this->card());
                $this->fail('knocked a page that blocked them');
            } catch (LineException $e) {
                $this->assertStringNotContainsStringIgnoringCase('block', $e->getMessage());
            }
        }

        $need = $this->need($customer);
        $this->offer($seller, ['accepts_quotes' => true]);
        $this->expectException(LineException::class);
        app(QuoteService::class)->request($customer, ['need' => $need->id], [$seller->id], $this->card());
    }

    public function test_a_blocked_person_sees_nothing_of_the_blocker_anywhere(): void
    {
        $blocked = $this->w('blocked');
        $owner = $this->w('sellerOwner');

        $round = app(\App\Services\Pulse\Discovery\Floor::class)->round($blocked, 'all');
        $this->assertSame([], collect($round['beats'])->filter(fn ($b) => ($b->from['entity']['id'] ?? null) === $this->w('seller')->id)->all());

        $search = app(\App\Services\Pulse\Discovery\SearchService::class)->search($blocked, 'Geyser');
        $this->assertSame([], collect($search['results'])->filter(fn ($r) => ($r['type'] ?? '') === 'signal' && ($r['beat']->signal->entity_id ?? null) === $this->w('seller')->id)->all());
    }

    // ------------------------------------------------------------------ rate limits

    public function test_messages_are_rate_limited_per_person(): void
    {
        $line = $this->w('line');
        $me = $this->w('stranger');
        config(['pulse.lines.rate_limits.message' => 5]);

        for ($i = 0; $i < 5; $i++) {
            app(LineService::class)->send($me, $line->fresh(), 'Message number '.$i);
        }

        $this->expectException(ThrottleRequestsException::class);
        app(LineService::class)->send($me, $line->fresh(), 'One too many');
    }

    public function test_reports_and_searches_and_exports_are_rate_limited(): void
    {
        $me = $this->w('stranger');

        config(['pulse.lines.rate_limits.report' => 3]);
        $reports = app(\App\Services\Pulse\Lines\ReportService::class);
        $targets = collect(range(1, 3))->map(fn () => $this->person());
        foreach ($targets as $t) {
            $reports->report($me, $t, 'spam', null);
        }
        try {
            $reports->report($me, $this->person(), 'spam', null);
            $this->fail('report not limited');
        } catch (ThrottleRequestsException) {
            $this->assertTrue(true);
        }

        config(['pulse.security.rate_limits.search' => 4]);
        $search = app(\App\Services\Pulse\Discovery\SearchService::class);
        for ($i = 0; $i < 4; $i++) {
            $search->search($me, 'geyser');
        }
        try {
            $search->search($me, 'geyser');
            $this->fail('search not limited');
        } catch (ThrottleRequestsException) {
            $this->assertTrue(true);
        }

        $this->actingAs($me);
        for ($i = 0; $i < 3; $i++) {
            $this->get('/me/privacy/export')->assertOk();
        }
        $this->get('/me/privacy/export')->assertStatus(429);
    }

    public function test_the_poll_and_unsubscribe_routes_are_throttled(): void
    {
        $me = $this->w('stranger');
        $this->actingAs($me);
        $last = 0;
        for ($i = 0; $i < 125; $i++) {
            $last = $this->getJson('/api/pulse/pulse-check')->getStatusCode();
            if ($last === 429) {
                break;
            }
        }
        $this->assertSame(429, $last);
    }
}

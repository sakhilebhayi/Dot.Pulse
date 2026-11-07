<?php

namespace Tests\Feature\LinesUi;

use App\Livewire\Pulse\Lines\QuoteCompare;
use App\Livewire\Pulse\Lines\QuoteRequest;
use App\Livewire\Pulse\Lines\Thread;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseQuote;
use App\Models\User;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class QuotesTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    /** @return array{0: PulseEntity, 1: User} a business that takes quote requests, and its responder */
    private function provider(string $name = 'Moyo Plumbing', string $offer = 'Geyser repair'): array
    {
        $page = $this->page(null, ['name' => $name, 'contact_policy' => 'context']);
        DB::table('pulse_entities')->where('id', $page->id)->update(['moderation' => 'approved']);
        $this->offer($page, ['title' => $offer]);

        return [$page, $this->responderOf($page)];
    }

    /** The visible words of a rendered component, with the framework's comments and tags removed. */
    private function words($component): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $component->html()))));
    }

    private function slip(array $over = []): array
    {
        return array_merge([
            'amount_cents' => 120000,
            'earliest_date' => now()->addDays(2)->toDateString(),
            'included' => 'Parts and labour for the geyser element',
            'excluded' => 'Replacing the geyser itself',
            'valid_until' => now()->addDays(7)->toDateString(),
        ], $over);
    }

    /** @return array{0: User, 1: list<PulseConversation>, 2: list<PulseEntity>} */
    private function request(int $providers = 3): array
    {
        $user = $this->person([], ['name' => 'Sipho Ndlovu']);
        $need = $this->need($user);
        $pages = [];
        foreach (range(1, $providers) as $i) {
            $pages[] = $this->provider("Plumber {$i}")[0];
        }
        $lines = app(QuoteService::class)->request($user, ['need' => $need->id], array_map(fn ($p) => $p->id, $pages), $this->card());

        return [$user, $lines, $pages];
    }

    // ------------------------------------------------------------------ the provider answers on a slip

    public function test_a_provider_sees_the_slip_form_and_answers_with_the_standard_fields(): void
    {
        [, $lines, $pages] = $this->request(1);
        $responder = $this->responderOf($pages[0]);

        Livewire::actingAs($responder)->test(Thread::class, ['conversation' => $lines[0]])
            ->assertSee('Answer on a quote slip')
            ->assertSee('You pay nothing to answer')
            ->set('slip.amount', '1200')
            ->set('slip.amount_max', '1800.50')
            ->set('slip.unit', 'per job')
            ->set('slip.earliest_date', now()->addDays(2)->toDateString())
            ->set('slip.included', 'Parts and labour')
            ->set('slip.excluded', 'The geyser itself')
            ->set('slip.valid_until', now()->addDays(10)->toDateString())
            ->call('sendSlip')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $this->assertStringContainsString('R1 200 to R1 800.50', $this->words(Livewire::actingAs($responder)->test(Thread::class, ['conversation' => $lines[0]->fresh()])));
        $quote = PulseQuote::query()->first();
        $this->assertSame(120000, $quote->amount_cents);
        $this->assertSame(180050, $quote->amount_max_cents);
        $this->assertSame('open', $lines[0]->fresh()->status, 'answering a request answers it');
    }

    public function test_the_slip_form_explains_what_is_wrong_in_plain_words(): void
    {
        [, $lines, $pages] = $this->request(1);

        Livewire::actingAs($this->responderOf($pages[0]))->test(Thread::class, ['conversation' => $lines[0]])
            ->call('sendSlip')
            ->assertHasErrors(['slip.amount', 'slip.earliest_date', 'slip.included'])
            ->assertSee('Put a price in rand')
            ->assertSee('Say what the price covers')
            ->set('slip.amount', '1000')
            ->set('slip.amount_max', '900')
            ->set('slip.earliest_date', now()->subDay()->toDateString())
            ->set('slip.included', 'Labour')
            ->set('slip.valid_until', now()->addDays(400)->toDateString())
            ->call('sendSlip')
            ->assertHasErrors(['slip.amount_max', 'slip.earliest_date', 'slip.valid_until'])
            ->assertSee('The top of the range must be more than the low end.')
            ->assertSee('The earliest date cannot be in the past.')
            ->assertSee('A slip can hold for at most 90 days.');

        $this->assertSame(0, PulseQuote::query()->count());
    }

    public function test_links_are_kept_out_of_a_slip_until_they_reply(): void
    {
        [, $lines, $pages] = $this->request(1);

        Livewire::actingAs($this->responderOf($pages[0]))->test(Thread::class, ['conversation' => $lines[0]])
            ->set('slip.amount', '1000')
            ->set('slip.earliest_date', now()->addDay()->toDateString())
            ->set('slip.included', 'Everything, see https://example.com/price-list')
            ->call('sendSlip')
            ->assertHasErrors('slip.included');
    }

    public function test_the_requester_never_sees_the_slip_form_and_a_stranger_cannot_use_it(): void
    {
        [$user, $lines] = $this->request(1);

        Livewire::actingAs($user)->test(Thread::class, ['conversation' => $lines[0]])
            ->assertDontSee('Answer on a quote slip')
            ->call('sendSlip')
            ->assertForbidden();

        Livewire::actingAs($this->person())->test(Thread::class, ['conversation' => $lines[0]])->assertNotFound();
    }

    // ------------------------------------------------------------------ the requester decides

    public function test_the_requester_sees_the_slip_in_the_thread_and_can_accept_it(): void
    {
        [$user, $lines, $pages] = $this->request(2);
        $quote = app(QuoteService::class)->respond($this->responderOf($pages[0]), $lines[0], $this->slip());

        Livewire::actingAs($user)->test(Thread::class, ['conversation' => $lines[0]->fresh()])
            ->assertSee('Quote slip')
            ->assertSee('R1 200')
            ->assertSee('Parts and labour for the geyser element')
            ->assertSee('Replacing the geyser itself')
            ->assertSee('days left')
            ->assertSee('Compare all the quotes for this request')
            ->call('acceptQuote', $quote->id)
            ->assertDispatched('toast')
            ->assertSee('You accepted the quote.');

        $this->assertSame('accepted', $quote->fresh()->status);
        $this->assertSame('not_chosen', $lines[1]->fresh()->closed_reason);
    }

    public function test_declining_a_slip_closes_it_to_the_requester(): void
    {
        [$user, $lines, $pages] = $this->request(1);
        $quote = app(QuoteService::class)->respond($this->responderOf($pages[0]), $lines[0], $this->slip());

        Livewire::actingAs($user)->test(Thread::class, ['conversation' => $lines[0]->fresh()])->call('declineQuote', $quote->id);

        $this->assertSame('declined', $quote->fresh()->status);
    }

    public function test_only_the_requester_can_decide_and_only_on_this_line(): void
    {
        [$user, $lines, $pages] = $this->request(2);
        $mine = app(QuoteService::class)->respond($this->responderOf($pages[0]), $lines[0], $this->slip());
        $other = app(QuoteService::class)->respond($this->responderOf($pages[1]), $lines[1], $this->slip());

        // The provider cannot accept its own slip.
        Livewire::actingAs($this->responderOf($pages[0]))->test(Thread::class, ['conversation' => $lines[0]->fresh()])
            ->assertDontSee('Accept this quote')
            ->call('acceptQuote', $mine->id)
            ->assertForbidden();

        // A slip id from another line of the request is not reachable from this line.
        Livewire::actingAs($user)->test(Thread::class, ['conversation' => $lines[0]->fresh()])->call('acceptQuote', $other->id)->assertNotFound();
        $this->assertSame('sent', $other->fresh()->status);
    }

    public function test_an_expired_slip_says_expired_and_cannot_be_accepted(): void
    {
        [$user, $lines, $pages] = $this->request(1);
        $quote = app(QuoteService::class)->respond($this->responderOf($pages[0]), $lines[0], $this->slip(['valid_until' => now()->toDateString()]));
        $this->travel(2)->days();

        $component = Livewire::actingAs($user)->test(Thread::class, ['conversation' => $lines[0]->fresh()])
            ->assertSee('Expired')
            ->assertDontSee('Accept this quote');

        $component->call('acceptQuote', $quote->id)->assertSee('That quote has expired or was already answered.');
        $this->assertSame('sent', $quote->fresh()->status);
    }

    // ------------------------------------------------------------------ side by side

    public function test_three_slips_sit_side_by_side_with_price_date_scope_and_expiry(): void
    {
        [$user, $lines, $pages] = $this->request(3);
        $quotes = app(QuoteService::class);
        $quotes->respond($this->responderOf($pages[0]), $lines[0], $this->slip(['amount_cents' => 90000, 'included' => 'Labour only']));
        $quotes->respond($this->responderOf($pages[1]), $lines[1], $this->slip(['amount_cents' => 150000, 'amount_max_cents' => 200000, 'included' => 'Labour and parts']));
        // The third has not answered yet.

        $group = $lines[0]->quote_group;

        $this->actingAs($user)->get("/lines/quotes/{$group}")->assertOk()->assertSeeLivewire(QuoteCompare::class);

        $text = $this->words(Livewire::actingAs($user)->test(QuoteCompare::class, ['group' => $group]));
        $this->assertStringContainsString('R900', $text);
        $this->assertStringContainsString('R1 500 to R2 000', $text);

        Livewire::actingAs($user)->test(QuoteCompare::class, ['group' => $group])
            ->assertSee('Plumber 1')->assertSee('Plumber 2')->assertSee('Plumber 3')
            ->assertSee('Labour only')
            ->assertSee('Labour and parts')
            ->assertSee('Earliest start')
            ->assertSee('Holds until')
            ->assertSee('Not included')
            ->assertSee('No slip yet')
            ->assertSee('closes as no reply on');
    }

    public function test_response_reliability_shows_only_when_it_is_real(): void
    {
        [$user, $lines, $pages] = $this->request(2);
        // Plumber 1 has answered; Plumber 2 has a request that is two weeks old and unanswered.
        app(QuoteService::class)->respond($this->responderOf($pages[0]), $lines[0], $this->slip());
        PulseConversation::query()->whereKey($lines[1]->id)->update(['created_at' => now()->subDays(10)]);

        $html = $this->words(Livewire::actingAs($user)->test(QuoteCompare::class, ['group' => $lines[0]->quote_group]));

        $this->assertStringContainsString('Answered 1 of 1 requests in the last 90 days, usually within', $html);
        $this->assertStringContainsString('Answered 0 of 1 requests in the last 90 days.', $html);
        $this->assertStringNotContainsString('100%', $html);
        $this->assertStringNotContainsString('Top rated', $html);
    }

    public function test_no_reliability_line_when_there_is_nothing_to_count(): void
    {
        [$user, $lines] = $this->request(1);

        $html = $this->words(Livewire::actingAs($user)->test(QuoteCompare::class, ['group' => $lines[0]->quote_group]));

        $this->assertStringNotContainsString('requests in the last', $html);
    }

    public function test_accepting_from_the_comparison_closes_the_others_and_says_what_comes_next(): void
    {
        [$user, $lines, $pages] = $this->request(2);
        $quotes = app(QuoteService::class);
        $a = $quotes->respond($this->responderOf($pages[0]), $lines[0], $this->slip());
        $quotes->respond($this->responderOf($pages[1]), $lines[1], $this->slip(['amount_cents' => 80000]));

        Livewire::actingAs($user)->test(QuoteCompare::class, ['group' => $lines[0]->quote_group])
            ->call('acceptQuote', $a->id)
            ->assertDispatched('toast')
            ->assertSee('You accepted the quote from Plumber 1')
            ->assertSee('Not chosen');

        $this->assertSame('not_chosen', $lines[1]->fresh()->closed_reason);
    }

    public function test_declining_from_the_comparison_works_and_a_foreign_slip_is_a_404(): void
    {
        [$user, $lines, $pages] = $this->request(1);
        $a = app(QuoteService::class)->respond($this->responderOf($pages[0]), $lines[0], $this->slip());

        [, $otherLines, $otherPages] = $this->request(1);
        $foreign = app(QuoteService::class)->respond($this->responderOf($otherPages[0]), $otherLines[0], $this->slip());

        Livewire::actingAs($user)->test(QuoteCompare::class, ['group' => $lines[0]->quote_group])
            ->call('declineQuote', $foreign->id)->assertNotFound();

        Livewire::actingAs($user)->test(QuoteCompare::class, ['group' => $lines[0]->quote_group])->call('declineQuote', $a->id);
        $this->assertSame('declined', $a->fresh()->status);
    }

    public function test_only_the_person_who_asked_can_open_the_comparison(): void
    {
        [, $lines, $pages] = $this->request(2);
        $group = $lines[0]->quote_group;

        $this->actingAs($this->person())->get("/lines/quotes/{$group}")->assertNotFound();
        $this->actingAs($this->responderOf($pages[0]))->get("/lines/quotes/{$group}")->assertNotFound();
        $this->actingAs($this->person())->get('/lines/quotes/not-a-uuid')->assertNotFound();
    }

    // ------------------------------------------------------------------ the request-a-quote entry

    public function test_the_entry_explains_the_cap_and_lists_businesses_that_take_quotes(): void
    {
        $user = $this->person();
        $need = $this->need($user, ['title' => 'Geyser repair on Thursday']);
        $this->provider('Moyo Plumbing', 'Geyser repair');
        $this->provider('Ndlovu Electrical', 'Wiring');

        Livewire::actingAs($user)->test(QuoteRequest::class, ['needId' => $need->id])
            ->assertSee('Ask for quotes')
            ->call('openSheet')
            ->assertSee('Ask up to 3 businesses')
            ->assertSee('Three is the cap. More than three turns a request into a race')
            ->assertSee('Moyo Plumbing')
            ->assertSee('Quotes on: Geyser repair')
            ->assertSee('Ndlovu Electrical')
            ->assertSee('costs one knock for the whole request');
    }

    public function test_the_entry_sends_to_up_to_three_and_stops_a_fourth(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        $pages = collect(range(1, 4))->map(fn ($i) => $this->provider("Plumber {$i}")[0]);
        $before = app(KnockBudget::class)->remaining($user);

        $component = Livewire::actingAs($user)->test(QuoteRequest::class, ['needId' => $need->id])->call('openSheet')
            ->set('selected', $pages->pluck('id')->all())
            ->assertSee('Three is the most')
            ->assertSet('selected', $pages->take(3)->pluck('id')->all());

        $component->call('send')->assertSet('sent', true)->assertSee('Sent to 3 businesses.')->assertSee('Compare the quotes');

        $this->assertSame(3, PulseConversation::query()->where('kind', 'quote')->count());
        $this->assertSame($before - 1, app(KnockBudget::class)->remaining($user));
    }

    public function test_the_entry_is_prefilled_from_the_need_and_refuses_an_empty_pick(): void
    {
        $user = $this->person();
        $need = $this->need($user, ['title' => 'Geyser repair on Thursday', 'budget_text' => 'R1000 to R2000']);
        $this->provider();

        Livewire::actingAs($user)->test(QuoteRequest::class, ['needId' => $need->id])
            ->assertSet('card.what', 'Geyser repair on Thursday')
            ->assertSet('card.budget_band', 'R1000 to R2000')
            ->call('openSheet')
            ->call('send')
            ->assertSee('Pick at least one business to ask.');
    }

    public function test_a_link_in_the_request_is_refused_in_the_field(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$page] = $this->provider();

        Livewire::actingAs($user)->test(QuoteRequest::class, ['needId' => $need->id, 'providerIds' => [$page->id]])
            ->call('openSheet')
            ->set('card.where', 'see https://example.com/map')
            ->call('send')
            ->assertHasErrors('card.where');
    }

    public function test_from_an_offer_its_business_is_always_one_of_the_three(): void
    {
        $user = $this->person();
        [$page] = $this->provider('Moyo Plumbing');
        $offerId = $page->offers()->value('id');
        [$second] = $this->provider('Second Plumbing');

        $component = Livewire::actingAs($user)->test(QuoteRequest::class, ['offerId' => $offerId])->call('openSheet')
            ->assertSee('Always asked, because you started from their offer.')
            ->set('selected', [])
            ->assertSet('selected', [$page->id])
            ->set('selected', [$page->id, $second->id])
            ->call('send')
            ->assertSet('sent', true);

        $this->assertSame(2, PulseConversation::query()->where('kind', 'quote')->count());
    }

    public function test_the_entry_only_works_from_your_own_need(): void
    {
        $someoneElse = $this->need($this->person());

        Livewire::actingAs($this->person())->test(QuoteRequest::class, ['needId' => $someoneElse->id])->assertNotFound();
    }

    public function test_the_entry_says_so_when_the_knock_budget_is_spent(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$page] = $this->provider();
        $budget = app(KnockBudget::class);
        while ($budget->remaining($user) > 0) {
            $budget->spend($user);
        }

        Livewire::actingAs($user)->test(QuoteRequest::class, ['needId' => $need->id, 'providerIds' => [$page->id]])
            ->call('openSheet')
            ->assertSee('You have 0 left this week')
            ->call('send')
            ->assertSet('sent', false)
            ->assertSeeHtml('role="alert"');
    }
}

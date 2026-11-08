<?php

namespace Tests\Feature\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseQuote;
use App\Models\User;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\QuoteService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class QuoteServiceTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function quotes(): QuoteService
    {
        return app(QuoteService::class);
    }

    /** @return array{0: PulseEntity, 1: User} */
    private function provider(string $name = 'Moyo Plumbing'): array
    {
        $page = $this->page(null, ['name' => $name, 'contact_policy' => 'context']);
        $this->offer($page);

        return [$page, $this->responderOf($page)];
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

    public function test_a_request_goes_to_up_to_three_providers_on_one_card_and_costs_one_knock(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a] = $this->provider('A Plumbing');
        [$b] = $this->provider('B Plumbing');
        [$c] = $this->provider('C Plumbing');
        $before = app(KnockBudget::class)->remaining($user);

        $lines = $this->quotes()->request($user, ['need' => $need->id], [$a->id, $b->id, $c->id], $this->card());

        $this->assertCount(3, $lines);
        $this->assertSame(1, collect($lines)->pluck('quote_group')->unique()->count());
        $this->assertSame($before - 1, app(KnockBudget::class)->remaining($user), 'one knock for the whole request');
        foreach ($lines as $line) {
            $this->assertSame('quote', $line->kind);
            $this->assertSame('requested', $line->status);
            $this->assertSame('need', $line->context_type);
            $this->assertSame('quote_request', $line->messages()->first()->kind);
        }

        // Each provider hears about it and pays nothing: no budget row exists for a page.
        $this->assertSame(1, $this->responderOf($a)->notifications()->where('data', 'like', '%quote_requested%')->count());
    }

    public function test_a_fourth_provider_is_refused_and_so_is_none(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        $ids = collect(range(1, 4))->map(fn () => $this->provider()[0]->id)->all();

        try {
            $this->quotes()->request($user, ['need' => $need->id], $ids, $this->card());
            $this->fail('max three');
        } catch (LineException $e) {
            $this->assertSame('limit', $e->reason);
        }

        $this->expectException(LineException::class);
        $this->quotes()->request($user, ['need' => $need->id], [], $this->card());
    }

    public function test_duplicates_in_the_list_do_not_sneak_past_the_cap_or_double_send(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a] = $this->provider();

        $lines = $this->quotes()->request($user, ['need' => $need->id], [$a->id, $a->id, $a->id], $this->card());

        $this->assertCount(1, $lines);
    }

    public function test_a_request_starts_from_the_senders_own_live_need_or_a_quoting_offer(): void
    {
        $user = $this->person();
        [$a] = $this->provider();

        $someoneElsesNeed = $this->need($this->person());
        try {
            $this->quotes()->request($user, ['need' => $someoneElsesNeed->id], [$a->id], $this->card());
            $this->fail('not your need');
        } catch (LineException $e) {
            $this->assertSame('unavailable', $e->reason);
        }

        $closed = $this->need($user, ['status' => 'closed']);
        $this->expectException(LineException::class);
        $this->quotes()->request($user, ['need' => $closed->id], [$a->id], $this->card());
    }

    public function test_asking_from_an_offer_includes_its_page_and_respects_the_cap(): void
    {
        $user = $this->person();
        [$a] = $this->provider('Offer Owner');
        [$b] = $this->provider('Second');
        $offer = $a->offers()->first();

        $lines = $this->quotes()->request($user, ['offer' => $offer->id], [$b->id], $this->card());

        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($lines)->pluck('entity_id')->all());

        $noQuotes = $this->offer($b, ['accepts_quotes' => false]);
        $this->expectException(LineException::class);
        $this->quotes()->request($user, ['offer' => $noQuotes->id], [], $this->card());
    }

    public function test_a_provider_that_is_closed_to_contact_or_not_a_seller_stops_the_whole_request(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a] = $this->provider();
        $nobody = $this->page(null, ['contact_policy' => 'nobody']);
        $this->offer($nobody);

        try {
            $this->quotes()->request($user, ['need' => $need->id], [$a->id, $nobody->id], $this->card());
            $this->fail('nobody');
        } catch (LineException $e) {
            $this->assertStringContainsString($nobody->name, $e->getMessage());
        }
        $this->assertSame(0, PulseConversation::count(), 'all or nothing');

        $notSeller = $this->page(null, ['is_seller' => false, 'is_employer' => true]);
        $this->expectException(LineException::class);
        $this->quotes()->request($user, ['need' => $need->id], [$notSeller->id], $this->card());
    }

    public function test_links_in_the_request_card_are_refused(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a] = $this->provider();

        $this->expectException(ValidationException::class);
        $this->quotes()->request($user, ['need' => $need->id], [$a->id], $this->card(['what' => 'see www.example.com']));
    }

    public function test_a_provider_responds_with_a_slip_and_the_line_opens(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a, $owner] = $this->provider();
        [$line] = $this->quotes()->request($user, ['need' => $need->id], [$a->id], $this->card());
        $budget = app(KnockBudget::class);
        $afterRequest = $budget->remaining($user);

        $quote = $this->quotes()->respond($owner, $line, $this->slip(['amount_max_cents' => 150000, 'unit' => 'job']));

        $this->assertSame('sent', $quote->status);
        $this->assertTrue($quote->isRange());
        $line->refresh();
        $this->assertSame('open', $line->status);
        $this->assertNotNull($line->first_response_at);
        $this->assertSame($afterRequest + 1, $budget->remaining($user), 'a reply refills one');
        $note = $user->notifications()->where('data', 'like', '%quote_received%')->first();
        $this->assertStringContainsString('Moyo Plumbing', $note->data['title']);
        $this->assertStringContainsString('R1 200 to R1 500', $note->data['body']);
        $this->assertSame('quote', $line->messages()->where('kind', 'quote')->first()->kind);
    }

    public function test_slip_validation(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a, $owner] = $this->provider();
        [$line] = $this->quotes()->request($user, ['need' => $need->id], [$a->id], $this->card());

        $bad = [
            'no price' => [['amount_cents' => null], 'amount_cents'],
            'zero price' => [['amount_cents' => 0], 'amount_cents'],
            'range inverted' => [['amount_max_cents' => 100], 'amount_max_cents'],
            'date in the past' => [['earliest_date' => now()->subDay()->toDateString()], 'earliest_date'],
            'no included' => [['included' => ''], 'included'],
            'valid in the past' => [['valid_until' => now()->subDay()->toDateString()], 'valid_until'],
            'valid too long' => [['valid_until' => now()->addDays(120)->toDateString()], 'valid_until'],
            'link in included' => [['included' => 'see www.mysite.co.za for more'], 'included'],
            'phone in excluded' => [['excluded' => 'call 082 123 4567'], 'excluded'],
        ];

        foreach ($bad as $label => [$over, $field]) {
            try {
                $this->quotes()->respond($owner, $line, $this->slip($over));
                $this->fail("{$label} should have been refused");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), $label);
            }
        }

        $this->assertSame(0, PulseQuote::count());
    }

    public function test_who_may_respond_accept_and_decline(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a, $owner] = $this->provider();
        $editor = $this->member($a, null, 'editor');
        $stranger = $this->person();
        [$line] = $this->quotes()->request($user, ['need' => $need->id], [$a->id], $this->card());

        foreach ([$user, $editor, $stranger] as $nope) {
            try {
                $this->quotes()->respond($nope, $line, $this->slip());
                $this->fail('only the provider\'s responders can send a slip');
            } catch (AuthorizationException) {
            }
        }

        $quote = $this->quotes()->respond($owner, $line, $this->slip());

        foreach ([$owner, $stranger, $editor] as $nope) {
            try {
                $this->quotes()->accept($nope, $quote);
                $this->fail('only the person who asked decides');
            } catch (AuthorizationException) {
            }
            try {
                $this->quotes()->decline($nope, $quote);
                $this->fail('only the person who asked decides');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame('sent', $quote->fresh()->status);
    }

    public function test_accepting_one_quote_tells_the_other_providers_they_were_not_chosen(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a, $ownerA] = $this->provider('A Plumbing');
        [$b, $ownerB] = $this->provider('B Plumbing');
        [$c, $ownerC] = $this->provider('C Plumbing'); // never replies
        $lines = $this->quotes()->request($user, ['need' => $need->id], [$a->id, $b->id, $c->id], $this->card());
        $qa = $this->quotes()->respond($ownerA, $lines[0], $this->slip(['amount_cents' => 90000]));
        $qb = $this->quotes()->respond($ownerB, $lines[1], $this->slip(['amount_cents' => 110000]));

        $this->quotes()->accept($user, $qb);

        $this->assertSame('accepted', $qb->fresh()->status);
        $this->assertSame('declined', $qa->fresh()->status);
        $this->assertSame('closed', $lines[0]->fresh()->status);
        $this->assertSame('not_chosen', $lines[0]->fresh()->closed_reason);
        $this->assertSame('not_chosen', $lines[2]->fresh()->closed_reason, 'a provider who never replied is also told');
        $this->assertSame('open', $lines[1]->fresh()->status);
        $this->assertSame(1, $ownerA->notifications()->where('data', 'like', '%quote_not_chosen%')->count());
        $this->assertSame(1, $ownerC->notifications()->where('data', 'like', '%quote_not_chosen%')->count());
        $this->assertSame(1, $ownerB->notifications()->where('data', 'like', '%quote_accepted%')->count());
    }

    public function test_declining_a_quote_keeps_the_line_for_questions_and_tells_the_provider(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a, $owner] = $this->provider();
        [$line] = $this->quotes()->request($user, ['need' => $need->id], [$a->id], $this->card());
        $quote = $this->quotes()->respond($owner, $line, $this->slip());

        $this->quotes()->decline($user, $quote);

        $this->assertSame('declined', $quote->fresh()->status);
        $this->assertSame('open', $line->fresh()->status);
        $this->assertSame(1, $owner->notifications()->where('data', 'like', '%quote_declined%')->count());

        $this->expectException(LineException::class);
        $this->quotes()->accept($user, $quote->fresh());
    }

    public function test_an_expired_or_already_answered_quote_cannot_be_accepted(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a, $owner] = $this->provider();
        [$line] = $this->quotes()->request($user, ['need' => $need->id], [$a->id], $this->card());
        $quote = $this->quotes()->respond($owner, $line, $this->slip(['valid_until' => now()->addDay()->toDateString()]));

        $this->travel(3)->days();

        try {
            $this->quotes()->accept($user, $quote->fresh());
            $this->fail('expired');
        } catch (LineException $e) {
            $this->assertSame('not_open', $e->reason);
        }
    }

    public function test_a_revised_slip_supersedes_the_old_one(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a, $owner] = $this->provider();
        [$line] = $this->quotes()->request($user, ['need' => $need->id], [$a->id], $this->card());
        $first = $this->quotes()->respond($owner, $line, $this->slip(['amount_cents' => 150000]));
        $second = $this->quotes()->respond($owner, $line->fresh(), $this->slip(['amount_cents' => 130000]));

        $this->assertSame('superseded', $first->fresh()->status);
        $this->assertSame('sent', $second->fresh()->status);
    }

    public function test_stale_quotes_expire_and_both_sides_are_told(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        [$a, $owner] = $this->provider();
        [$line] = $this->quotes()->request($user, ['need' => $need->id], [$a->id], $this->card());
        $quote = $this->quotes()->respond($owner, $line, $this->slip(['valid_until' => now()->addDays(2)->toDateString()]));

        $this->assertSame(0, $this->quotes()->expireStale());

        $this->travel(3)->days();
        $this->assertSame(1, $this->quotes()->expireStale());
        $this->assertSame(0, $this->quotes()->expireStale(), 'idempotent');

        $this->assertSame('expired', $quote->fresh()->status);
        $this->assertSame(1, $user->notifications()->where('data', 'like', '%quote_expired%')->count());
        $this->assertSame(1, $owner->notifications()->where('data', 'like', '%quote_expired%')->count());
    }
}

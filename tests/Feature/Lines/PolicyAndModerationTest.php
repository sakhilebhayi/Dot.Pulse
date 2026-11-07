<?php

namespace Tests\Feature\Lines;

use App\Models\PulseConversation;
use App\Models\PulseMessage;
use App\Services\Pulse\Lines\Doors;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use App\Services\Pulse\Lines\QuoteService;
use App\Services\Pulse\Lines\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class PolicyAndModerationTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private const LOCAL_RULES = 'App\\Services\\Pulse\\Discovery\\Moderation\\LocalRules';

    private function rules(string $verdict, ?\Closure $capture = null): void
    {
        $this->app->instance(self::LOCAL_RULES, new class($verdict, $capture)
        {
            public function __construct(private string $verdict, private ?\Closure $capture) {}

            public function assess(string $text, $author, array $ctx = []): array
            {
                if ($this->capture) {
                    ($this->capture)($text, $author, $ctx);
                }

                return ['verdict' => $this->verdict, 'flags' => ['test']];
            }
        });
    }

    // ------------------------------------------------------------------- the policy matrix

    public function test_conversation_policy_matrix(): void
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $responder = $this->member($page, null, 'responder');
        $admin = $this->member($page, null, 'admin');
        $editor = $this->member($page, null, 'editor');
        $stranger = $this->person();
        $lines = app(LineService::class);

        $requested = $lines->knock($customer, ['entity' => $page->id], 'enquiry', $this->card());
        $open = $this->openLine($this->person(), $page);

        // [ability, user, expected on the requested line]
        $matrix = [
            ['view', $customer, true], ['view', $owner, true], ['view', $responder, true], ['view', $admin, true], ['view', $editor, false], ['view', $stranger, false],
            ['accept', $customer, false], ['accept', $owner, true], ['accept', $responder, true], ['accept', $admin, true], ['accept', $editor, false], ['accept', $stranger, false],
            ['decline', $customer, false], ['decline', $owner, true], ['decline', $editor, false], ['decline', $stranger, false],
            ['ignore', $customer, false], ['ignore', $owner, true], ['ignore', $stranger, false],
            ['close', $customer, true], ['close', $owner, false], ['close', $stranger, false],   // the sender withdraws; the recipient declines
            ['send', $customer, false], ['send', $owner, false],                                  // not open yet
            ['report', $customer, true], ['report', $owner, true], ['report', $editor, false], ['report', $stranger, false],
            ['reveal', $customer, false], ['reveal', $owner, false],
            ['outcome', $customer, false], ['outcome', $owner, false],                            // never answered
        ];

        foreach ($matrix as [$ability, $user, $expected]) {
            $this->assertSame($expected, $user->can($ability, $requested), "{$ability} on a requested line for user {$user->id}");
        }

        $openMatrix = [
            ['send', $owner, true], ['send', $responder, true], ['send', $editor, false], ['send', $stranger, false],
            ['close', $owner, true], ['close', $responder, true], ['close', $stranger, false],
            ['accept', $owner, false], ['outcome', $owner, true], ['outcome', $stranger, false], ['outcome', $editor, false],
        ];

        foreach ($openMatrix as [$ability, $user, $expected]) {
            $this->assertSame($expected, $user->can($ability, $open), "{$ability} on an open line for user {$user->id}");
        }
    }

    public function test_quote_policy_matrix(): void
    {
        $asker = $this->person();
        $need = $this->need($asker);
        $page = $this->page(null, ['contact_policy' => 'context']);
        $this->offer($page);
        $owner = $this->responderOf($page);
        $editor = $this->member($page, null, 'editor');
        $stranger = $this->person();
        [$line] = app(QuoteService::class)->request($asker, ['need' => $need->id], [$page->id], $this->card());
        $quote = app(QuoteService::class)->respond($owner, $line, [
            'amount_cents' => 1000, 'earliest_date' => now()->toDateString(), 'included' => 'Everything', 'valid_until' => now()->addDays(5)->toDateString(),
        ]);

        $this->assertTrue($asker->can('view', $quote));
        $this->assertTrue($owner->can('view', $quote));
        $this->assertFalse($stranger->can('view', $quote));
        $this->assertFalse($editor->can('view', $quote));
        $this->assertTrue($asker->can('accept', $quote));
        $this->assertTrue($asker->can('decline', $quote));
        $this->assertFalse($owner->can('accept', $quote));
        $this->assertFalse($stranger->can('decline', $quote));
        $this->assertTrue($owner->can('respond', $line->fresh()));
        $this->assertFalse($editor->can('respond', $line->fresh()));
        $this->assertFalse($asker->can('respond', $line->fresh()));
        $this->assertFalse($stranger->can('respond', $line->fresh()));
    }

    public function test_the_author_is_not_serialised_with_a_message_and_the_recipient_not_with_a_conversation(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $c = $this->openLine($this->person(), $page);

        $this->assertStringNotContainsString('user_id', PulseMessage::first()->toJson());
        $this->assertArrayNotHasKey('recipient_id', $c->toArray());
    }

    // ------------------------------------------------------------------- the age gate and budget through knock

    public function test_knock_refuses_the_age_gate_and_the_budget_with_distinct_reasons(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $lines = app(LineService::class);

        try {
            $lines->knock($this->minor(), ['entity' => $page->id], 'enquiry', $this->card());
            $this->fail('age gate');
        } catch (LineException $e) {
            $this->assertSame('age', $e->reason);
        }

        $broke = $this->person();
        while (app(KnockBudget::class)->remaining($broke) > 0) {
            app(KnockBudget::class)->spend($broke);
        }
        try {
            $lines->knock($broke, ['entity' => $page->id], 'enquiry', $this->card());
            $this->fail('budget');
        } catch (LineException $e) {
            $this->assertSame('budget', $e->reason);
        }

        $this->assertSame(0, PulseConversation::count());
    }

    public function test_a_page_that_takes_anyone_opens_a_direct_line_through_open(): void
    {
        $page = $this->page(null, ['contact_policy' => 'anyone']);
        $dm = app(LineService::class)->open($this->person(), $page);

        $this->assertSame('open', $dm->status);
        $this->assertSame($page->id, $dm->entity_id);
    }

    public function test_the_hourly_knock_burst_is_bounded_too(): void
    {
        config(['pulse.lines.rate_limits.knock' => 2]);
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $sender = $this->person();
        $lines = app(LineService::class);

        $lines->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());
        $lines->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(['what' => 'Another thing']), $this->offer($page));

        $this->expectException(ThrottleRequestsException::class);
        $lines->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(['what' => 'A third thing']), $this->role($page));
    }

    // ------------------------------------------------------------------- the moderation seam (LocalRules is written by another agent)

    public function test_a_block_verdict_refuses_a_knock_and_nothing_is_spent_or_stored(): void
    {
        $this->rules('block');
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $sender = $this->person();

        try {
            app(LineService::class)->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());
            $this->fail('blocked by the rules');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('breaks the Pulse rules', $e->errors()['card.what'][0]);
        }

        $this->assertSame(0, PulseConversation::count());
        $this->assertSame(7, app(KnockBudget::class)->remaining($sender));
    }

    public function test_the_rules_are_asked_with_the_text_the_author_and_the_surface(): void
    {
        $seen = [];
        $this->rules('allow', function ($text, $author, $ctx) use (&$seen) {
            $seen[] = [$text, $author->id, $ctx['surface'] ?? null];
        });
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'anyone']);

        $c = app(LineService::class)->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());
        app(LineService::class)->send($sender, $c, 'a plain follow up');

        $surfaces = array_column($seen, 2);
        $this->assertContains('knock', $surfaces);
        $this->assertContains('message', $surfaces);
        $this->assertSame($sender->id, $seen[0][1]);
    }

    public function test_a_block_verdict_refuses_a_message_a_door_and_a_review(): void
    {
        $page = $this->page(null, ['contact_policy' => 'anyone']);
        $customer = $this->person();
        $c = app(LineService::class)->knock($customer, ['entity' => $page->id], 'enquiry', $this->card());

        $this->rules('block');

        try {
            app(LineService::class)->send($customer, $c, 'fine words');
            $this->fail('message');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }

        try {
            app(Doors::class)->open($this->person(), 'A perfectly normal purpose', 'day');
            $this->fail('door');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('purpose', $e->errors());
        }
    }

    public function test_a_hold_verdict_publishes_a_door_as_held_and_holds_a_review_back(): void
    {
        $this->rules('hold');

        $signal = app(Doors::class)->open($this->person(), 'A perfectly normal purpose', 'day');
        $this->assertSame('held', $signal->status);

        $this->app->forgetInstance(self::LOCAL_RULES);
        $this->rules('allow');
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $c = $this->openLine($customer, $page);
        app(OutcomeService::class)->propose($customer, $c, 'won');
        app(OutcomeService::class)->confirm($owner, $c->fresh());

        $this->rules('hold');
        $review = app(ReviewService::class)->submit($customer, $c->fresh(), ['rating' => 5, 'body' => 'Questionable but probably fine']);

        $this->assertSame('held', $review->moderation);
        $this->assertCount(0, app(ReviewService::class)->forSubject($page));
    }

    public function test_a_failing_rules_engine_does_not_stop_contact(): void
    {
        $this->app->instance(self::LOCAL_RULES, new class
        {
            public function assess(): array
            {
                throw new \RuntimeException('rules crashed');
            }
        });

        $c = app(LineService::class)->knock($this->person(), ['entity' => $this->page(null, ['contact_policy' => 'requests'])->id], 'enquiry', $this->card());

        $this->assertSame('requested', $c->status);
    }
}

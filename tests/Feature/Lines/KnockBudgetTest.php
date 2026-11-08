<?php

namespace Tests\Feature\Lines;

use App\Models\PulseKnockBudget;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class KnockBudgetTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function budget(): KnockBudget
    {
        return app(KnockBudget::class);
    }

    public function test_an_established_account_gets_seven_and_a_new_one_three(): void
    {
        $this->assertSame(7, $this->budget()->remaining($this->person()));
        $this->assertSame(3, $this->budget()->remaining($this->person([], ['created_at' => now()->subDays(2)])));
        $this->assertSame(7, $this->budget()->remaining($this->person([], ['created_at' => now()->subDays(8)])));
    }

    public function test_spending_counts_down_and_stops_at_zero_with_a_clear_message(): void
    {
        $u = $this->person([], ['created_at' => now()->subDays(1)]);

        foreach ([3, 2, 1] as $expected) {
            $this->assertSame($expected, $this->budget()->remaining($u));
            $this->budget()->spend($u);
        }

        $this->assertSame(0, $this->budget()->remaining($u));

        try {
            $this->budget()->spend($u);
            $this->fail('should be out');
        } catch (LineException $e) {
            $this->assertSame('budget', $e->reason);
            $this->assertStringContainsString('refill', $e->getMessage());
        }

        $this->assertSame(3, PulseKnockBudget::where('user_id', $u->id)->value('spent'), 'a refused spend is not counted');
    }

    public function test_the_window_rolls_after_seven_days(): void
    {
        $u = $this->person();
        foreach (range(1, 7) as $i) {
            $this->budget()->spend($u);
        }
        $this->assertSame(0, $this->budget()->remaining($u));
        $resets = $this->budget()->resetsAt($u);
        $this->assertTrue($resets->isSameDay(now()->addDays(7)));

        $this->travel(7)->days();
        Cache::flush();

        $this->assertSame(7, $this->budget()->remaining($u));
        $this->assertTrue($this->budget()->resetsAt($u)->isFuture());
    }

    public function test_a_reply_from_a_stranger_refills_one_and_only_once_per_line(): void
    {
        $sender = $this->person();
        $target = $this->policy($this->person(), 'requests');
        $lines = app(LineService::class);

        $c = $lines->knock($sender, ['user' => $target->id], 'intro', $this->card());
        $this->assertSame(6, $this->budget()->remaining($sender));

        $lines->accept($target, $c);
        $this->assertSame(7, $this->budget()->remaining($sender), 'the accept refunds one first contact');

        $lines->send($target, $c->fresh(), 'And a message too');
        $lines->send($target, $c->fresh(), 'And another');
        $this->assertSame(7, $this->budget()->remaining($sender), 'only once per line');
    }

    public function test_a_decline_does_not_refill(): void
    {
        $sender = $this->person();
        $target = $this->policy($this->person(), 'requests');
        $lines = app(LineService::class);

        $c = $lines->knock($sender, ['user' => $target->id], 'intro', $this->card());
        $lines->decline($target, $c);

        $this->assertSame(6, $this->budget()->remaining($sender));
    }

    public function test_refills_are_capped_so_two_accounts_cannot_pump_each_other(): void
    {
        $u = $this->person();

        foreach (range(1, 12) as $i) {
            $this->budget()->refill($u);
        }

        $this->assertSame(7 + 5, $this->budget()->remaining($u));
    }

    public function test_confirmed_spam_shrinks_the_allowance_and_it_heals_a_step_each_clean_window(): void
    {
        $u = $this->person();
        $this->budget()->shrink($u, 2);
        $this->assertSame(5, $this->budget()->remaining($u));

        $this->budget()->shrink($u, 100);
        $this->assertSame(0, $this->budget()->remaining($u), 'never below zero and never more than the base');

        $this->travel(7)->days();
        Cache::flush();
        $this->assertSame(1, $this->budget()->remaining($u), 'one step healed');

        $this->travel(7)->days();
        Cache::flush();
        $this->assertSame(2, $this->budget()->remaining($u));
    }

    public function test_a_stale_view_of_the_budget_cannot_overspend(): void
    {
        $u = $this->person([], ['created_at' => now()->subDays(1)]);
        $a = new KnockBudget;
        $b = new KnockBudget;

        $this->budget()->spend($u);
        $this->budget()->spend($u); // one left
        $this->assertSame(1, $a->remaining($u)); // process A reads "1 left" (and caches it)

        $b->spend($u); // process B takes the last one in between

        // Process A acts on its stale read. The spend is one conditional UPDATE, so it loses cleanly.
        try {
            $a->spend($u);
            $this->fail('the last knock was already taken');
        } catch (LineException $e) {
            $this->assertSame('budget', $e->reason);
        }

        $this->assertSame(3, PulseKnockBudget::where('user_id', $u->id)->value('spent'));
    }

    public function test_the_spend_is_an_atomic_conditional_update(): void
    {
        $u = $this->person([], ['created_at' => now()->subDays(1)]);
        $this->budget()->remaining($u);
        DB::enableQueryLog();

        $this->budget()->spend($u);

        $updates = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'update "pulse_knock_budgets"'));
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('spent < ', $updates->first()['query'], 'the cap is checked inside the UPDATE');
    }

    public function test_a_failed_knock_does_not_spend_budget(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);

        try {
            app(LineService::class)->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(['what' => 'see https://spam.example']));
        } catch (ValidationException) {
        }

        $this->assertSame(7, $this->budget()->remaining($sender));
    }

    public function test_a_known_contact_costs_nothing(): void
    {
        $sender = $this->person();
        $friend = $this->policy($this->person(), 'requests');
        $lines = app(LineService::class);
        $c = $lines->knock($sender, ['user' => $friend->id], 'intro', $this->card());
        $lines->accept($friend, $c);
        $lines->close($sender, $c->fresh());
        $before = $this->budget()->remaining($sender);

        $second = $lines->knock($sender, ['user' => $friend->id], 'collab', $this->card(['what' => 'A different idea']));

        $this->assertSame($before, $this->budget()->remaining($sender));
        $this->assertSame('open', $second->status, 'known contacts are direct');
    }

    public function test_there_is_no_way_to_buy_knocks(): void
    {
        $public = collect((new ReflectionClass(KnockBudget::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn ($m) => $m->getName())->sort()->values()->all();

        $this->assertSame(['base', 'refill', 'remaining', 'resetsAt', 'shrink', 'spend'], $public);
    }
}

<?php

namespace Tests\Feature\Lines;

use App\Models\PulseDoor;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\ContactGate;
use App\Services\Pulse\Lines\Doors;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class ContactGateTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function gate(): ContactGate
    {
        return app(ContactGate::class);
    }

    public function test_anyone_policy_is_direct_with_or_without_context(): void
    {
        $from = $this->person();
        $target = $this->policy($this->person(), 'anyone');

        $r = $this->gate()->can($from, $target);
        $this->assertTrue($r['allowed']);
        $this->assertSame('direct', $r['mode']);
        $this->assertTrue($r['stranger']);
    }

    public function test_requests_policy_always_becomes_a_request(): void
    {
        $from = $this->person();
        $target = $this->policy($this->person(), 'requests');

        $r = $this->gate()->can($from, $target);
        $this->assertTrue($r['allowed']);
        $this->assertSame('request', $r['mode']);
    }

    public function test_nobody_policy_blocks_everything_even_with_a_live_context(): void
    {
        $from = $this->person();
        $owner = $this->policy($this->person(), 'nobody');
        $need = $this->need($owner);

        $r = $this->gate()->can($from, $owner, $need);
        $this->assertFalse($r['allowed']);
        $this->assertSame('blocked', $r['mode']);
        $this->assertSame('nobody', $r['code']);
    }

    public function test_context_policy_needs_a_live_context_that_belongs_to_the_target(): void
    {
        $from = $this->person();
        $owner = $this->policy($this->person(), 'context');

        $none = $this->gate()->can($from, $owner);
        $this->assertFalse($none['allowed']);
        $this->assertSame('needs_context', $none['code']);

        $ownersNeed = $this->need($owner);
        $ok = $this->gate()->can($from, $owner, $ownersNeed);
        $this->assertTrue($ok['allowed']);
        $this->assertSame('request', $ok['mode']);

        $someoneElses = $this->need($this->person());
        $this->assertSame('needs_context', $this->gate()->can($from, $owner, $someoneElses)['code']);

        $expired = $this->need($owner, ['expires_at' => now()->subDay()]);
        $this->assertSame('needs_context', $this->gate()->can($from, $owner, $expired)['code']);

        $held = $this->need($owner, ['moderation' => 'held']);
        $this->assertSame('needs_context', $this->gate()->can($from, $owner, $held)['code']);
    }

    public function test_a_page_with_context_policy_takes_requests_only_from_its_own_live_listings(): void
    {
        $from = $this->person();
        $page = $this->page(null, ['contact_policy' => 'context']);
        $other = $this->page();

        $this->assertSame('needs_context', $this->gate()->can($from, $page)['code']);
        $this->assertSame('request', $this->gate()->can($from, $page, $this->offer($page))['mode']);
        $this->assertSame('needs_context', $this->gate()->can($from, $page, $this->offer($other))['code'], 'someone else\'s offer is not a context');
        $this->assertSame('needs_context', $this->gate()->can($from, $page, $this->offer($page, ['status' => 'paused']))['code']);
        $this->assertSame('needs_context', $this->gate()->can($from, $page, $this->offer($page, ['expires_at' => now()->subHour()]))['code']);
        $this->assertSame('request', $this->gate()->can($from, $page, $this->role($page))['mode']);
    }

    public function test_the_senders_own_need_is_a_context_for_a_seller_that_quotes(): void
    {
        $from = $this->person();
        $page = $this->page(null, ['contact_policy' => 'context']);
        $need = $this->need($from);

        $this->assertSame('needs_context', $this->gate()->can($from, $page, $need)['code'], 'no quoting offer yet');

        $this->offer($page, ['accepts_quotes' => true]);
        $this->assertSame('request', $this->gate()->can($from, $page, $need)['mode']);
    }

    public function test_the_age_gate_works_in_both_directions_without_saying_why_to_the_sender(): void
    {
        $adult = $this->person();
        $minor = $this->minor();
        $open = $this->policy($this->person(), 'anyone');

        $fromMinor = $this->gate()->can($minor, $open);
        $this->assertFalse($fromMinor['allowed']);
        $this->assertSame('age', $fromMinor['code']);

        $toMinor = $this->gate()->can($adult, $this->policy($minor, 'anyone'));
        $this->assertFalse($toMinor['allowed']);
        $this->assertSame('unavailable', $toMinor['code']);
        $this->assertStringNotContainsStringIgnoringCase('18', $toMinor['why']);
    }

    public function test_blocks_hide_both_ways_and_never_say_so(): void
    {
        $a = $this->person();
        $b = $this->policy($this->person(), 'anyone');
        app(BlockService::class)->block($a, $b);

        $ab = $this->gate()->can($a, $b);
        $ba = $this->gate()->can($b, $this->policy($a, 'anyone'));

        foreach ([$ab, $ba] as $r) {
            $this->assertFalse($r['allowed']);
            $this->assertSame('unavailable', $r['code']);
            $this->assertStringNotContainsStringIgnoringCase('block', $r['why']);
        }
    }

    public function test_a_page_that_blocked_you_or_you_blocked_is_unavailable(): void
    {
        $from = $this->person();
        $page = $this->page();

        app(BlockService::class)->blockAsEntity($this->responderOf($page), $page, $from);
        $this->assertSame('unavailable', $this->gate()->can($from, $page)['code']);

        $other = $this->person();
        app(BlockService::class)->block($other, $page);
        $this->assertSame('unavailable', $this->gate()->can($other, $page)['code']);
    }

    public function test_self_and_own_page_are_refused(): void
    {
        $u = $this->person();
        $page = $this->page($u);

        $this->assertSame('self', $this->gate()->can($u, $u)['code']);
        $this->assertSame('own_page', $this->gate()->can($u, $page)['code']);
    }

    public function test_a_page_that_is_not_live_cannot_be_contacted(): void
    {
        $page = $this->page(null, ['status' => 'draft']);

        $this->assertSame('page_closed', $this->gate()->can($this->person(), $page)['code']);
    }

    public function test_an_empty_budget_blocks_strangers_but_not_people_you_already_talk_to(): void
    {
        $from = $this->person();
        $target = $this->policy($this->person(), 'requests');
        $friend = $this->policy($this->person(), 'requests');
        $budget = app(KnockBudget::class);

        // A known relationship first, while there is budget.
        $c = app(LineService::class)->knock($from, ['user' => $friend->id], 'intro', $this->card());
        app(LineService::class)->accept($friend, $c);

        while ($budget->remaining($from) > 0) {
            $budget->spend($from);
        }

        $stranger = $this->gate()->can($from, $target);
        $this->assertFalse($stranger['allowed']);
        $this->assertSame('budget', $stranger['code']);
        $this->assertStringContainsString('refill', $stranger['why']);

        $known = $this->gate()->can($from, $friend);
        $this->assertTrue($known['allowed']);
        $this->assertFalse($known['stranger']);
        $this->assertSame('direct', $known['mode']);
    }

    public function test_a_known_relationship_does_not_get_around_nobody(): void
    {
        $from = $this->person();
        $friend = $this->policy($this->person(), 'requests');
        $c = app(LineService::class)->knock($from, ['user' => $friend->id], 'intro', $this->card());
        app(LineService::class)->accept($friend, $c);

        $this->policy($friend, 'nobody');

        $this->assertSame('nobody', $this->gate()->can($from, $friend->fresh())['code']);
    }

    public function test_an_open_door_turns_context_and_requests_into_a_request_but_not_nobody(): void
    {
        $from = $this->person();
        $owner = $this->policy($this->person(), 'context');

        $signal = app(Doors::class)->open($owner, 'Looking for a baker for Saturday', 'day');
        $door = PulseDoor::query()->where('signal_id', $signal->id)->firstOrFail();

        $this->assertSame('request', $this->gate()->can($from, $door)['mode']);
        $this->assertSame('request', $this->gate()->can($from, $signal)['mode']);

        $this->policy($owner, 'nobody');
        $this->assertSame('nobody', $this->gate()->can($from, $door)['code']);
    }

    public function test_a_closed_door_is_not_a_context(): void
    {
        $from = $this->person();
        $owner = $this->policy($this->person(), 'context');
        $signal = app(Doors::class)->open($owner, 'Looking for a baker for Saturday', 'day');
        $door = PulseDoor::query()->where('signal_id', $signal->id)->firstOrFail();

        app(Doors::class)->close($owner, $door);

        $this->assertSame('needs_context', $this->gate()->can($from, $door->fresh())['code']);
    }

    public function test_a_user_with_no_profile_is_treated_as_context_only_and_is_not_contactable_until_age_confirmed(): void
    {
        $from = $this->person();
        $bare = \App\Models\User::factory()->create();

        $r = $this->gate()->can($from, $bare);
        $this->assertFalse($r['allowed']);
        $this->assertSame('unavailable', $r['code']);

        DB::table('pulse_profiles')->insert(['user_id' => $bare->id, 'role' => 'customer', 'age_confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('needs_context', $this->gate()->can($from, $bare->fresh())['code']);
    }
}

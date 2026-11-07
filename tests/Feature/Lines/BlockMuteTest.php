<?php

namespace Tests\Feature\Lines;

use App\Models\PulseBlock;
use App\Models\PulseConversation;
use App\Models\PulseMute;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\MuteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class BlockMuteTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function blocks(): BlockService
    {
        return app(BlockService::class);
    }

    public function test_blocking_hides_both_ways(): void
    {
        $a = $this->person();
        $b = $this->person();

        $this->assertFalse(BlockService::blocked($a, $b));
        $this->blocks()->block($a, $b);

        $this->assertTrue(BlockService::blocked($a, $b));
        $this->assertTrue(BlockService::blocked($b, $a), 'the blocked person is hidden from the blocker and vice versa');
        $this->assertSame([$b->id], BlockService::hiddenUserIds($a));
        $this->assertSame([$a->id], BlockService::hiddenUserIds($b));
        $this->assertSame([], BlockService::hiddenUserIds($this->person()));
    }

    public function test_blocking_is_idempotent_and_audited_and_reversible(): void
    {
        $a = $this->person();
        $b = $this->person();

        $this->blocks()->block($a, $b);
        $this->blocks()->block($a, $b);
        $this->assertSame(1, PulseBlock::count());
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'block.user', 'actor_id' => $a->id]);

        $this->blocks()->unblock($a, $b);
        $this->assertFalse(BlockService::blocked($a, $b));
        $this->assertSame([], BlockService::hiddenUserIds($b));
    }

    public function test_you_cannot_block_yourself(): void
    {
        $a = $this->person();

        $this->expectException(LineException::class);
        $this->blocks()->block($a, $a);
    }

    public function test_blocking_closes_open_and_requested_lines_in_both_directions_and_severs_follows(): void
    {
        $a = $this->policy($this->person(), 'requests');
        $b = $this->policy($this->person(), 'requests');
        $lines = app(LineService::class);

        $requested = $lines->knock($a, ['user' => $b->id], 'intro', $this->card());
        $open = $lines->knock($b, ['user' => $a->id], 'collab', $this->card(['what' => 'Collaborate on a pitch']));
        $lines->accept($a, $open);
        $a->following()->attach($b->id);
        $b->following()->attach($a->id);

        $this->blocks()->block($b, $a);

        $this->assertSame('closed', $requested->fresh()->status);
        $this->assertSame('closed', $open->fresh()->status);
        $this->assertSame('blocked', $open->fresh()->closed_reason);
        $this->assertSame(0, DB::table('pulse_followers')->count());

        // The blocked person is not told: they see an ordinary closed line.
        $this->assertSame('closed', $lines->present($a, $open->fresh())['status']);
        $this->assertSame(0, $a->notifications()->where('data', 'like', '%block%')->count());
    }

    public function test_blocking_closes_lines_through_a_page_the_blocked_person_answers_for(): void
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $line = $this->openLine($customer, $page);

        $this->blocks()->block($customer, $owner);

        $this->assertSame('closed', $line->fresh()->status);
    }

    public function test_a_person_can_block_a_page_and_a_page_can_block_a_person(): void
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $line = $this->openLine($customer, $page);

        $this->blocks()->block($customer, $page);

        $this->assertSame('closed', $line->fresh()->status);
        $this->assertSame([$page->id], BlockService::hiddenEntityIds($customer));
        $this->assertTrue(BlockService::entityHidden($customer, $page));

        $this->blocks()->unblock($customer, $page);
        $this->assertFalse(BlockService::entityHidden($customer, $page));

        $second = $this->openLine($customer, $page);
        $this->blocks()->blockAsEntity($this->responderOf($page), $page, $customer);
        $this->assertSame('closed', $second->fresh()->status);
        $this->assertTrue(BlockService::entityHidden($customer, $page), 'hidden from the person too');
    }

    public function test_only_a_responder_can_block_for_a_page_and_members_cannot_block_their_own_page(): void
    {
        $page = $this->page();
        $editor = $this->member($page, null, 'editor');
        $stranger = $this->person();

        foreach ([$editor, $stranger] as $nope) {
            try {
                $this->blocks()->blockAsEntity($nope, $page, $this->person());
                $this->fail('not a responder');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
            }
        }

        $this->expectException(LineException::class);
        $this->blocks()->block($editor, $page);
    }

    // ------------------------------------------------------------------- mutes

    public function test_mutes_are_quiet_per_kind_and_case_insensitive(): void
    {
        $u = $this->person();
        $mutes = app(MuteService::class);

        $mutes->mute($u, 'topic', 'Crypto');
        $mutes->mute($u, 'topic', 'crypto');
        $mutes->mute($u, 'entity', '42');
        $mutes->mute($u, 'user', '7');

        $this->assertSame(3, PulseMute::count());
        $this->assertTrue(MuteService::isMuted($u, 'topic', 'CRYPTO'));
        $this->assertTrue(MuteService::isMuted($u, 'entity', 42));
        $this->assertFalse(MuteService::isMuted($u, 'topic', 'baking'));
        $this->assertFalse(MuteService::isMuted($this->person(), 'topic', 'crypto'), 'mutes are personal');
        $this->assertSame(['crypto'], MuteService::mutedValues($u, 'topic'));

        $mutes->unmute($u, 'topic', 'crypto');
        $this->assertFalse(MuteService::isMuted($u, 'topic', 'crypto'));
    }

    public function test_mute_input_is_checked_and_bounded(): void
    {
        $u = $this->person();
        $mutes = app(MuteService::class);

        foreach ([['colour', 'red'], ['topic', ''], ['topic', str_repeat('x', 121)]] as [$kind, $value]) {
            try {
                $mutes->mute($u, $kind, $value);
                $this->fail('refused');
            } catch (LineException $e) {
                $this->assertSame('invalid', $e->reason);
            }
        }

        foreach (range(1, MuteService::MAX) as $i) {
            PulseMute::create(['user_id' => $u->id, 'kind' => 'topic', 'target' => "t{$i}"]);
        }

        $this->expectException(LineException::class);
        $mutes->mute($u, 'topic', 'one-more');
    }
}

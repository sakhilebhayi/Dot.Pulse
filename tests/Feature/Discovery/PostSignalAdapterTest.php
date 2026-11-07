<?php

namespace Tests\Feature\Discovery;

use App\Jobs\ModeratePost;
use App\Models\Community;
use App\Models\PulsePost;
use App\Models\PulseSignal;
use App\Services\AiModerationService;
use App\Services\ModerationActions;
use App\Services\Pulse\Discovery\PostSignalAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class PostSignalAdapterTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private function adapter(): PostSignalAdapter
    {
        return app(PostSignalAdapter::class);
    }

    public function test_questions_events_and_everything_else_map_to_ask_event_and_news(): void
    {
        foreach (['question' => 'ask', 'event' => 'event', 'discussion' => 'news', 'article' => 'news', 'job' => 'news', 'marketplace' => 'news'] as $type => $kind) {
            $post = PulsePost::factory()->create(['type' => $type]);

            $this->assertSame($kind, $this->adapter()->toSignalAttributes($post)['kind'], $type);
        }
    }

    public function test_attributes_describe_the_post_without_leaking_anything_private(): void
    {
        $g = $this->dGeo();
        $author = $this->dUser(['place' => $g->pta]);
        $post = PulsePost::factory()->create(['user_id' => $author->id, 'title' => 'Which tiles for a wet room?', 'body' => 'We are renovating a bathroom in Pretoria and need advice on tiling and waterproofing for the shower area.', 'type' => 'question']);

        $a = $this->adapter()->toSignalAttributes($post);

        $this->assertSame('post', $a['subject_type']);
        $this->assertSame($post->id, $a['subject_id']);
        $this->assertSame($author->id, $a['owner_id']);
        $this->assertSame('Which tiles for a wet room?', $a['title']);
        $this->assertContains('tiling', $a['topics']);
        $this->assertContains('waterproofing', $a['topics']);
        $this->assertSame($g->pta->id, $a['place_id']);
        $this->assertSame('live', $a['status']);
        $this->assertFalse($a['anonymous']);
        $this->assertLessThanOrEqual(280, mb_strlen($a['excerpt']));
        $this->assertGreaterThanOrEqual(0, $a['quality']);
        $this->assertLessThanOrEqual(1, $a['quality']);
    }

    public function test_status_follows_the_post_and_a_private_community_post_is_never_discoverable(): void
    {
        $this->assertSame('live', $this->adapter()->toSignalAttributes(PulsePost::factory()->create())['status']);
        $this->assertSame('held', $this->adapter()->toSignalAttributes(PulsePost::factory()->status('pending')->create())['status']);
        $this->assertSame('held', $this->adapter()->toSignalAttributes(PulsePost::factory()->status('flagged')->create())['status']);
        $this->assertSame('removed', $this->adapter()->toSignalAttributes(PulsePost::factory()->status('removed')->create())['status']);
        $this->assertSame('paused', $this->adapter()->toSignalAttributes(PulsePost::factory()->status('draft')->create())['status']);

        $private = Community::factory()->private()->create();
        $inPrivate = PulsePost::factory()->create(['community_id' => $private->id]);
        $this->assertSame('paused', $this->adapter()->toSignalAttributes($inPrivate)['status']);
        $this->assertNull($this->adapter()->sync($inPrivate), 'no signal is even created for it');

        $deleted = PulsePost::factory()->create();
        $deleted->delete();
        $this->assertSame('removed', $this->adapter()->toSignalAttributes($deleted)['status']);
    }

    public function test_sync_creates_updates_and_hides(): void
    {
        $post = PulsePost::factory()->create(['title' => 'Original']);
        $signal = $this->adapter()->sync($post);
        $this->assertSame('live', $signal->status);

        $post->update(['title' => 'Changed']);
        $this->adapter()->sync($post->fresh());
        $this->assertSame(1, PulseSignal::where('subject_id', $post->id)->count());
        $this->assertSame('Changed', $signal->fresh()->title);

        $post->update(['status' => 'removed']);
        $this->adapter()->sync($post->fresh());
        $this->assertSame('removed', $signal->fresh()->status);
    }

    public function test_an_existing_row_keeps_its_subject_type_spelling_so_the_key_never_forks(): void
    {
        $post = PulsePost::factory()->create();
        $classSpelled = PulseSignal::create(array_merge($this->adapter()->toSignalAttributes($post), ['subject_type' => PulsePost::class]));

        $this->adapter()->sync($post);

        $this->assertSame(1, PulseSignal::count());
        $this->assertSame(PulsePost::class, $classSpelled->fresh()->subject_type);
    }

    public function test_moderation_puts_a_published_post_on_the_index_and_a_held_one_off_it(): void
    {
        $author = $this->dUser();
        $ok = PulsePost::factory()->status('pending')->create(['user_id' => $author->id, 'body' => 'Great paving workshop next Saturday in Midrand, all welcome.']);
        $held = PulsePost::factory()->status('pending')->create(['user_id' => $author->id, 'body' => 'Call me on 082 555 1234 about the paving.']);

        (new ModeratePost($ok))->handle(app(AiModerationService::class));
        (new ModeratePost($held))->handle(app(AiModerationService::class));

        $this->assertSame('live', PulseSignal::where('subject_id', $ok->id)->value('status'));
        $this->assertNull(PulseSignal::where('subject_id', $held->id)->first());
    }

    public function test_a_moderator_action_moves_the_signal_at_once(): void
    {
        $moderator = $this->dUser(['place' => null]);
        $actions = app(ModerationActions::class);

        $held = PulsePost::factory()->status('flagged')->create();
        $this->assertTrue($actions->approvePost($held, $moderator));
        $this->assertSame('live', PulseSignal::where('subject_id', $held->id)->value('status'));

        $this->assertTrue($actions->removePost($held->fresh(), $moderator, 'Not suitable.'));
        $this->assertSame('removed', PulseSignal::where('subject_id', $held->id)->value('status'));

        $other = PulsePost::factory()->status('flagged')->create();
        $this->assertTrue($actions->rejectPost($other, $moderator, 'No.'));
        $this->assertNull(PulseSignal::where('subject_id', $other->id)->first());
    }
}

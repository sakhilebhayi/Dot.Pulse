<?php

namespace Tests\Feature\Pulse;

use App\Console\Commands\SweepPendingPosts;
use App\Events\PostPublished;
use App\Jobs\ModeratePost;
use App\Livewire\Pulse\CreatePost;
use App\Livewire\Pulse\MyPostStatus;
use App\Livewire\Pulse\PostShow;
use App\Models\PulseModerationLog;
use App\Models\PulsePost;
use App\Services\AiModerationService;
use App\Services\ModerationUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class AiModerationFailClosedTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    /** R-41: a post the local rules hold for a person (a phone number in public text). */
    private const HOLD_BODY = 'Call me on 082 555 1234 about the van, I am in Benoni.';

    private function pending(array $attrs = []): PulsePost
    {
        return PulsePost::factory()->status('pending')->create($attrs);
    }

    private function pendingHold(array $attrs = []): PulsePost
    {
        return $this->pending(array_merge(['body' => self::HOLD_BODY], $attrs));
    }

    private function claudeReplies(array $verdict, int $status = 200): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($verdict)]],
        ], $status)]);
    }

    public function test_the_api_key_is_read_from_the_configured_service_key(): void
    {
        // CreatePost used to read services.anthropic.key, which does not
        // exist, so production always ran the mock and approved everything.
        // R-41: a configured model is consulted only for a post the local rules held, and only for a second
        // opinion: it can never publish it.
        config(['services.anthropic.api_key' => 'sk-test-key']);
        $this->claudeReplies(['moderation_status' => 'approved', 'moderation_rationale' => 'Looks like an ordinary sale.']);
        $post = $this->pendingHold();

        (new ModeratePost($post))->handle(app(AiModerationService::class));

        Http::assertSentCount(1);
        $post->refresh();
        $this->assertSame('flagged', $post->status);
        $this->assertStringContainsString('Second opinion (model): approved', $post->enrichment->moderation_rationale);
    }

    public function test_a_post_the_local_rules_allow_never_calls_the_model(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key']);
        Http::fake();
        $post = $this->pending();

        (new ModeratePost($post))->handle(app(AiModerationService::class));

        Http::assertNothingSent();
        $this->assertSame('published', $post->fresh()->status);
    }

    public function test_the_request_carries_the_right_headers_model_and_timeout(): void
    {
        config([
            'services.anthropic.api_key' => 'sk-test-key',
            'services.anthropic.model' => 'claude-test-model-9',
        ]);
        $this->claudeReplies(['moderation_status' => 'approved']);

        (new ModeratePost($this->pendingHold()))->handle(app(AiModerationService::class));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'sk-test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['model'] === 'claude-test-model-9';
        });
    }

    public function test_a_missing_key_no_longer_holds_every_post_forever(): void
    {
        // The production defect: with no key every post was held for a human and nothing ever published.
        Event::fake([PostPublished::class]);
        config(['services.anthropic.api_key' => '']);
        Http::fake();
        $post = $this->pending();

        (new ModeratePost($post))->handle(app(AiModerationService::class));

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertSame('approved', $post->enrichment->moderation_status);
        $this->assertDatabaseHas('pulse_moderation_logs', [
            'target_type' => PulsePost::class,
            'target_id' => $post->id,
            'action' => 'approve',
            'is_ai_decision' => true,
        ]);
        Http::assertNothingSent();
        Event::assertDispatched(PostPublished::class);
    }

    public function test_a_missing_key_still_holds_a_risky_post_for_a_human(): void
    {
        Event::fake([PostPublished::class]);
        config(['services.anthropic.api_key' => '']);
        Http::fake();
        $post = $this->pendingHold();

        (new ModeratePost($post))->handle(app(AiModerationService::class));

        $post->refresh();
        $this->assertSame('flagged', $post->status);
        $this->assertSame('flagged', $post->enrichment->moderation_status);
        $this->assertStringContainsString('contact_in_public', $post->enrichment->moderation_rationale);
        $this->assertDatabaseHas('pulse_moderation_logs', [
            'target_type' => PulsePost::class,
            'target_id' => $post->id,
            'action' => 'flag',
            'is_ai_decision' => true,
        ]);
        Http::assertNothingSent();
        Event::assertNotDispatched(PostPublished::class);
    }

    public function test_a_blocked_post_is_rejected_with_a_reason_the_author_can_read(): void
    {
        Event::fake([PostPublished::class]);
        $author = $this->pulseUser();
        $post = $this->pending(['user_id' => $author->id, 'title' => 'Great opportunity for everyone', 'body' => 'Double your money in 48 hours with our guaranteed returns, join today.']);

        (new ModeratePost($post))->handle(app(AiModerationService::class));

        $this->assertSame('removed', $post->fresh()->status);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_id' => $post->id, 'action' => 'reject', 'is_ai_decision' => true]);
        Event::assertNotDispatched(PostPublished::class);

        Livewire::actingAs($author)->test(MyPostStatus::class)
            ->assertSee('Great opportunity for everyone')
            ->assertSee('Removed')
            ->assertSee('Promises of guaranteed or doubled returns are not allowed');
    }

    public function test_a_failed_second_opinion_never_stops_a_held_post_waiting_for_a_person(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key']);
        $this->claudeReplies([], 529);
        $post = $this->pendingHold();

        (new ModeratePost($post))->handle(app(AiModerationService::class));

        $post->refresh();
        $this->assertSame('flagged', $post->status);
        $this->assertStringContainsString('Second opinion unavailable', $post->enrichment->moderation_rationale);
    }

    public function test_when_the_last_try_fails_the_post_is_held_for_review_not_approved(): void
    {
        Event::fake([PostPublished::class]);
        $post = $this->pending();

        (new ModeratePost($post))->failed(new \RuntimeException('Connection timed out'));

        $this->assertSame('flagged', $post->fresh()->status);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_id' => $post->id, 'action' => 'flag', 'is_ai_decision' => true]);
        Event::assertNotDispatched(PostPublished::class);
    }

    public function test_a_timeout_or_unparseable_answer_never_approves(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key']);
        $service = app(AiModerationService::class);

        Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        try {
            $service->classify($this->pending());
            $this->fail('A timeout must not produce a verdict.');
        } catch (ModerationUnavailable) {
            $this->addToAssertionCount(1);
        }

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'sure, looks fine!']]], 200)]);
        try {
            $service->classify($this->pending());
            $this->fail('Prose with no JSON verdict must not approve.');
        } catch (ModerationUnavailable) {
            $this->addToAssertionCount(1);
        }

        // JSON with no moderation_status used to default to "approved".
        $this->claudeReplies(['summary' => 'Looks ok']);
        $this->expectException(ModerationUnavailable::class);
        $service->classify($this->pending());
    }

    public function test_upsert_without_a_verdict_no_longer_defaults_to_approved(): void
    {
        $post = $this->pending();

        app(AiModerationService::class)->upsertEnrichment($post, ['summary' => 'No verdict at all.']);

        $this->assertSame('flagged', $post->fresh()->status);
    }

    public function test_an_approved_verdict_publishes_and_broadcasts(): void
    {
        Event::fake([PostPublished::class]);
        config(['services.anthropic.api_key' => 'sk-test-key']);
        $this->claudeReplies(['moderation_status' => 'approved', 'summary' => 'Fine.']);
        $post = $this->pending(['body' => 'Hello #newcomers, glad to be here.']);

        (new ModeratePost($post))->handle(app(AiModerationService::class));

        $this->assertSame('published', $post->fresh()->status);
        $this->assertNotNull($post->fresh()->published_at);
        Event::assertDispatched(PostPublished::class);
        $this->assertDatabaseHas('pulse_hashtags', ['name' => 'newcomers']);
    }

    public function test_rejected_and_flagged_verdicts_wait_for_a_human(): void
    {
        config(['services.anthropic.api_key' => 'sk-test-key']);

        // The model's verdict only annotates a post the rules held; it neither publishes nor removes it.
        foreach (['rejected', 'flagged', 'approved'] as $verdict) {
            $this->claudeReplies(['moderation_status' => $verdict, 'moderation_rationale' => 'Looks off.']);
            $post = $this->pendingHold();

            (new ModeratePost($post))->handle(app(AiModerationService::class));

            $this->assertSame('flagged', $post->fresh()->status);
        }
    }

    public function test_the_job_does_nothing_to_a_post_that_is_no_longer_pending(): void
    {
        Http::fake();
        config(['services.anthropic.api_key' => 'sk-test-key']);
        $post = PulsePost::factory()->create(); // already published

        (new ModeratePost($post))->handle(app(AiModerationService::class));

        Http::assertNothingSent();
    }

    public function test_the_job_is_configured_for_ten_second_calls_three_tries_and_backoff(): void
    {
        $job = new ModeratePost($this->pending());

        $this->assertSame(3, $job->tries);
        $this->assertGreaterThan(0, $job->timeout);
        $this->assertSame([10, 30], $job->backoff());
        $this->assertSame(10, (int) config('services.anthropic.timeout'));
    }

    public function test_creating_a_post_queues_moderation_instead_of_calling_the_api_in_the_request(): void
    {
        Queue::fake();
        Http::fake();
        $user = $this->pulseUser();

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('body', 'A perfectly ordinary post body for testing.')
            ->call('publish')
            ->assertHasNoErrors();

        Queue::assertPushed(ModeratePost::class);
        Http::assertNothingSent();
        $this->assertSame('pending', PulsePost::firstOrFail()->status);
    }

    public function test_end_to_end_without_a_key_an_ordinary_post_is_published(): void
    {
        config(['services.anthropic.api_key' => '']);
        $user = $this->pulseUser();

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('body', 'A perfectly ordinary post body for testing.')
            ->call('publish');

        $this->assertSame('published', PulsePost::firstOrFail()->status);
    }

    public function test_end_to_end_without_a_key_a_risky_post_is_held_for_a_person(): void
    {
        config(['services.anthropic.api_key' => '']);
        $user = $this->pulseUser();

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('body', self::HOLD_BODY)
            ->call('publish');

        $this->assertSame('flagged', PulsePost::firstOrFail()->status);
    }

    public function test_the_author_sees_a_clear_state_for_a_post_that_is_checking_or_held(): void
    {
        $author = $this->pulseUser();
        $checking = $this->pending(['user_id' => $author->id, 'title' => 'Draft one being checked right now']);
        $held = PulsePost::factory()->status('flagged')->create(['user_id' => $author->id, 'title' => 'Draft two waiting on a person']);

        Livewire::actingAs($author)->test(MyPostStatus::class)
            ->assertSee('Draft one being checked right now')
            ->assertSee('Checking')
            ->assertSee('Draft two waiting on a person')
            ->assertSee('Waiting for a moderator');

        Livewire::actingAs($author)->test(PostShow::class, ['post' => $held])
            ->assertSee('waiting for a moderator');
        Livewire::actingAs($author)->test(PostShow::class, ['post' => $checking])
            ->assertSee('being checked');
    }

    public function test_the_author_is_told_when_a_post_was_removed_and_why(): void
    {
        $author = $this->pulseUser();
        $post = PulsePost::factory()->status('removed')->create(['user_id' => $author->id, 'title' => 'The draft that was taken down']);
        PulseModerationLog::create([
            'moderator_id' => $this->platformModerator()->id,
            'target_type' => PulsePost::class,
            'target_id' => $post->id,
            'action' => 'reject',
            'rationale' => 'Contains a phone number that is not yours.',
        ]);

        Livewire::actingAs($author)->test(MyPostStatus::class)
            ->assertSee('The draft that was taken down')
            ->assertSee('Removed')
            ->assertSee('Contains a phone number that is not yours.');
    }

    public function test_other_users_cannot_see_someone_elses_held_posts_in_the_status_panel(): void
    {
        $this->pending(['title' => 'Somebody elses pending draft title']);

        Livewire::actingAs($this->pulseUser())->test(MyPostStatus::class)
            ->assertDontSee('Somebody elses pending draft title');
    }

    public function test_the_sweep_holds_posts_stuck_in_pending_so_a_dead_queue_cannot_hide_them_forever(): void
    {
        $stale = $this->pending();
        $stale->forceFill(['created_at' => now()->subMinutes(40)])->save();
        $fresh = $this->pending();

        $this->artisan(SweepPendingPosts::class, ['--minutes' => 15])->assertSuccessful();

        $this->assertSame('flagged', $stale->fresh()->status);
        $this->assertSame('pending', $fresh->fresh()->status);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_id' => $stale->id, 'action' => 'flag', 'is_ai_decision' => true]);
    }

    public function test_editing_a_live_post_sends_the_new_words_back_through_the_check(): void
    {
        Queue::fake();
        $author = $this->pulseUser();
        $post = PulsePost::factory()->create(['user_id' => $author->id, 'body' => 'The original wording of this post.']);

        Livewire::actingAs($author)->test(PostShow::class, ['post' => $post])
            ->call('startEditPost')
            ->set('editBody', 'Completely new words that were never checked.')
            ->call('saveEdit');

        $this->assertSame('pending', $post->fresh()->status);
        Queue::assertPushed(ModeratePost::class);
    }

    public function test_an_edit_that_changes_nothing_does_not_re_moderate(): void
    {
        Queue::fake();
        $author = $this->pulseUser();
        $post = PulsePost::factory()->create(['user_id' => $author->id, 'body' => 'The original wording of this post.']);

        Livewire::actingAs($author)->test(PostShow::class, ['post' => $post])
            ->call('startEditPost')
            ->call('saveEdit');

        $this->assertSame('published', $post->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_post_that_is_mid_check_cannot_be_edited(): void
    {
        $author = $this->pulseUser();
        $post = $this->pending(['user_id' => $author->id]);

        Livewire::actingAs($author)->test(PostShow::class, ['post' => $post])
            ->call('startEditPost')
            ->assertForbidden();
    }
}

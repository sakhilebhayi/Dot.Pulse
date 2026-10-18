<?php

namespace Tests\Feature\Pulse;

use App\Events\PostPublished;
use App\Models\PulsePost;
use App\Models\User;
use App\Services\AiModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AiModerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function pendingPost(string $body = 'A post awaiting moderation.'): PulsePost
    {
        $user = User::factory()->withPersonalTeam()->create();

        return PulsePost::create([
            'user_id' => $user->id,
            'team_id' => $user->currentTeam->id,
            'type' => 'discussion',
            'body' => $body,
            'status' => 'pending',
        ]);
    }

    public function test_approved_classification_still_auto_publishes(): void
    {
        Event::fake([PostPublished::class]);

        $post = $this->pendingPost('Excited to launch #dotagents today.');
        $service = new AiModerationService;

        $service->upsertEnrichment($post, [
            'moderation_status' => 'approved',
            'moderation_rationale' => null,
        ]);

        $this->assertSame('published', $post->fresh()->status);
        $this->assertDatabaseHas('pulse_moderation_logs', [
            'target_type' => PulsePost::class,
            'target_id' => $post->id,
            'action' => 'approve',
            'is_ai_decision' => true,
            'moderator_id' => null,
        ]);
        Event::assertDispatched(PostPublished::class, fn ($event) => $event->post->is($post));
        // HashtagExtractionService runs at the same publish point -- a
        // held/pending post's hashtags shouldn't be trending before this.
        $this->assertDatabaseHas('pulse_hashtags', ['name' => 'dotagents']);
    }

    public function test_rejected_classification_holds_for_review_instead_of_removing(): void
    {
        Event::fake([PostPublished::class]);

        $post = $this->pendingPost();
        $service = new AiModerationService;

        $service->upsertEnrichment($post, [
            'moderation_status' => 'rejected',
            'moderation_rationale' => 'Looks like spam.',
        ]);

        $this->assertSame('flagged', $post->fresh()->status);
        $this->assertDatabaseHas('pulse_moderation_logs', [
            'target_type' => PulsePost::class,
            'target_id' => $post->id,
            'action' => 'reject',
            'rationale' => 'Looks like spam.',
            'is_ai_decision' => true,
        ]);
        Event::assertNotDispatched(PostPublished::class);
    }

    public function test_flagged_classification_now_holds_for_review_instead_of_being_dropped(): void
    {
        $post = $this->pendingPost();
        $service = new AiModerationService;

        $service->upsertEnrichment($post, [
            'moderation_status' => 'flagged',
            'moderation_rationale' => 'Unsure about business relevance.',
        ]);

        $this->assertSame('flagged', $post->fresh()->status);
        $this->assertDatabaseHas('pulse_moderation_logs', [
            'target_type' => PulsePost::class,
            'target_id' => $post->id,
            'action' => 'flag',
            'is_ai_decision' => true,
        ]);
    }
}

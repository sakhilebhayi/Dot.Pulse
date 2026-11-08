<?php

namespace Tests\Feature\Entities;

use App\Models\PulseModerationLog;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Services\Pulse\Entities\ContentGate;
use App\Services\Pulse\Entities\Sanitizer;
use App\Services\Pulse\Entities\Slugger;
use Illuminate\Validation\ValidationException;

class ContentGateTest extends EntitiesTestCase
{
    private function gate(): ContentGate
    {
        return app(ContentGate::class);
    }

    public function test_with_no_rules_only_a_linked_clean_author_is_approved(): void
    {
        $this->assertSame('pending', $this->gate()->decide($this->person(), 'Fresh bread daily')['moderation']);

        $linked = $this->gate()->decide($this->infodotPerson(), 'Fresh bread daily');
        $this->assertSame('approved', $linked['moderation']);
        $this->assertSame('infodot_member', $linked['source']);
    }

    public function test_a_linked_author_with_a_removed_post_is_pending_again(): void
    {
        $author = $this->infodotPerson();
        $post = PulsePost::factory()->create(['user_id' => $author->id]);
        PulseModerationLog::create(['moderator_id' => null, 'target_type' => $post->getMorphClass(), 'target_id' => $post->id, 'action' => 'remove']);

        $this->assertSame('pending', $this->gate()->decide($author, 'Fresh bread daily')['moderation']);
    }

    public function test_a_linked_author_with_a_held_listing_is_pending_again(): void
    {
        $author = $this->infodotPerson();
        PulseOffer::factory()->create(['created_by' => $author->id, 'moderation' => 'held']);

        $this->assertSame('pending', $this->gate()->decide($author, 'Fresh bread daily')['moderation']);
    }

    public function test_rules_decide_when_present(): void
    {
        $author = $this->person();

        $this->rules(Fakes\AllowRules::class);
        $this->assertSame('approved', $this->gate()->decide($author, 'Fresh bread daily')['moderation']);

        $this->rules(Fakes\HoldRules::class);
        $verdict = $this->gate()->decide($author, 'Fresh bread daily');
        $this->assertSame('held', $verdict['moderation']);
        $this->assertSame(['link_density'], $verdict['flags']);

        $this->rules(Fakes\BlockRules::class);
        $this->expectException(ValidationException::class);
        $this->gate()->decide($author, 'Fresh bread daily');
    }

    public function test_a_rules_class_that_throws_falls_back_to_the_safe_path(): void
    {
        $this->rules(Fakes\BrokenRules::class);

        $this->assertSame('pending', $this->gate()->decide($this->person(), 'Fresh bread daily')['moderation']);
    }

    public function test_the_real_rules_class_is_used_by_default_when_it_exists(): void
    {
        config(['pulse.entities.rules_class' => null]);

        $verdict = $this->gate()->decide($this->person(), 'Fresh bread daily');

        $this->assertContains($verdict['moderation'], ['approved', 'pending', 'held']);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    public function test_sanitizer(): void
    {
        $this->assertSame('a b', Sanitizer::line("  <p>a</p>\n\t b ", 10));
        $this->assertSame('abcde', Sanitizer::line('abcdefgh', 5));
        $this->assertNull(Sanitizer::line('   ', 10));
        $this->assertNull(Sanitizer::text('<b></b>', 10));
        $this->assertSame("a\n\nb", Sanitizer::text("a\r\n\r\n\r\n\r\nb", 100));
        $this->assertSame('ab', Sanitizer::line("a\u{202E}\u{200B}\x07b", 10), 'control and bidi characters are dropped');
        $this->assertSame(['A', 'b'], Sanitizer::list(['A', 'a', ' b ', '', null, ['x']], 5, 10));
        $this->assertSame(['A'], Sanitizer::list(['A', 'B'], 1, 10));
        $this->assertSame('Call me or write', Sanitizer::withoutContactDetails('Call me +27 82 555 0147 or write x.y@example.org https://x.example'));
        $this->assertSame('Since 2019 we have 12 staff', Sanitizer::withoutContactDetails('Since 2019 we have 12 staff'));
    }

    public function test_slugger(): void
    {
        $taken = ['a-b', 'a-b-2'];

        $this->assertSame('a-b-3', Slugger::unique('A B', fn ($s) => in_array($s, $taken, true)));
        $this->assertSame('page', Slugger::unique('???', fn () => false));
        $this->assertSame('manage-page', Slugger::unique('Manage', fn () => false));
        $this->assertLessThanOrEqual(100, strlen(Slugger::unique(str_repeat('word ', 60), fn () => false)));
    }
}

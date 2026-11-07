<?php

namespace Tests\Feature\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseReport;
use App\Models\User;
use App\Notifications\PulseNotice;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\RetentionPurge;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

/** pulse:retention with frozen time: only what has aged past its period goes, and what must stay stays. */
class RetentionTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private PulseEntity $page;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->setTime(12, 0));
        $this->owner = $this->person();
        $this->page = $this->page($this->owner, ['contact_policy' => 'anyone']);
        $this->stranger = $this->person();
    }

    private function purge(): array
    {
        return app(RetentionPurge::class)->run();
    }

    /** A conversation with messages, in the given state and age. */
    private function line(string $status, $at, array $over = [], int $messages = 3): PulseConversation
    {
        $c = PulseConversation::query()->create(array_merge([
            'kind' => 'enquiry', 'status' => $status, 'initiator_id' => $this->stranger->id, 'entity_id' => $this->page->id,
            'first_response_at' => $at, 'last_message_at' => $at, 'closed_at' => in_array($status, ['closed', 'declined'], true) ? $at : null,
            'closed_reason' => $status === 'closed' ? 'closed' : null,
        ], $over));

        foreach (range(1, $messages) as $i) {
            DB::table('pulse_messages')->insert([
                'pulse_conversation_id' => $c->id, 'user_id' => $this->stranger->id, 'body' => "Private words number {$i}", 'kind' => 'text',
                'side' => 'initiator', 'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        DB::table('pulse_conversations')->where('id', $c->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $c->fresh();
    }

    public function test_messages_of_lines_closed_more_than_24_months_ago_become_stubs_and_the_rest_stay(): void
    {
        $old = $this->line('closed', now()->subMonths(25));
        $recent = $this->line('closed', now()->subMonths(23));
        $stillOpen = $this->line('open', now()->subMonths(30));

        $done = $this->purge();

        $this->assertSame(3, $done['messages_erased']);
        $stubs = DB::table('pulse_messages')->where('pulse_conversation_id', $old->id)->get();
        $this->assertCount(3, $stubs, 'the thread keeps its shape');
        $this->assertSame(['This message was deleted.'], $stubs->pluck('body')->unique()->all());
        $this->assertSame(['system'], $stubs->pluck('kind')->unique()->all());
        $this->assertTrue($stubs->every(fn ($m) => $m->erased_at !== null));

        $this->assertStringStartsWith('Private words', DB::table('pulse_messages')->where('pulse_conversation_id', $recent->id)->value('body'));
        $this->assertStringStartsWith('Private words', DB::table('pulse_messages')->where('pulse_conversation_id', $stillOpen->id)->value('body'), 'a line still open is never purged');
        $this->assertNotNull(PulseConversation::find($old->id), 'the conversation row stays');

        $this->assertSame(0, $this->purge()['messages_erased'], 'a second run finds nothing');
    }

    public function test_a_line_with_an_open_report_is_left_for_the_moderator(): void
    {
        $old = $this->line('closed', now()->subMonths(30));
        PulseReport::query()->create(['reporter_id' => $this->owner->id, 'reportable_type' => PulseConversation::class, 'reportable_id' => $old->id, 'reason' => 'spam', 'status' => PulseReport::OPEN]);

        $this->assertSame(0, $this->purge()['messages_erased']);
        $this->assertStringStartsWith('Private words', DB::table('pulse_messages')->where('pulse_conversation_id', $old->id)->value('body'));

        PulseReport::query()->update(['status' => PulseReport::RESOLVED]);
        $this->assertSame(3, $this->purge()['messages_erased']);
    }

    public function test_declined_and_ignored_requests_go_after_90_days_and_answered_lines_do_not(): void
    {
        $declinedOld = $this->line('declined', now()->subDays(91), ['first_response_at' => null], 1);
        $declinedNew = $this->line('declined', now()->subDays(89), ['first_response_at' => null], 1);
        $ignoredOld = $this->line('requested', now()->subDays(100), ['first_response_at' => null, 'ignored_at' => now()->subDays(91)], 1);
        $ignoredExpired = $this->line('closed', now()->subDays(100), ['first_response_at' => null, 'ignored_at' => now()->subDays(95), 'closed_reason' => 'expired'], 1);
        $ignoredNew = $this->line('requested', now()->subDays(20), ['first_response_at' => null, 'ignored_at' => now()->subDays(10)], 1);
        $answered = $this->line('declined', now()->subDays(200), ['first_response_at' => now()->subDays(201)], 1);

        $this->assertSame(3, $this->purge()['requests_deleted']);

        foreach ([$declinedOld, $ignoredOld, $ignoredExpired] as $gone) {
            $this->assertNull(PulseConversation::find($gone->id));
            $this->assertSame(0, DB::table('pulse_messages')->where('pulse_conversation_id', $gone->id)->count());
        }

        foreach ([$declinedNew, $ignoredNew, $answered] as $kept) {
            $this->assertNotNull(PulseConversation::find($kept->id));
        }
    }

    public function test_ended_signals_dismissed_matches_and_need_text_go_after_90_days(): void
    {
        $viewer = $this->person();
        $mk = fn (string $status, $expires, $updated) => DB::table('pulse_signals')->insertGetId([
            'kind' => 'offer', 'subject_type' => 'offer', 'subject_id' => random_int(1000, 999999), 'owner_id' => $this->owner->id, 'title' => 'T '.Str::random(5),
            'status' => $status, 'expires_at' => $expires, 'reach' => 'region', 'published_at' => $updated, 'created_at' => $updated, 'updated_at' => $updated,
        ]);
        $match = fn (int $signal, $dismissed = null) => DB::table('pulse_matches')->insertGetId(['user_id' => $viewer->id, 'signal_id' => $signal, 'score' => 1, 'reasons' => '[]', 'computed_at' => now(), 'dismissed_at' => $dismissed]);

        $expiredOld = $mk('expired', now()->subDays(91), now()->subDays(91));
        $expiredNew = $mk('expired', now()->subDays(10), now()->subDays(10));
        $removedOld = $mk('removed', null, now()->subDays(120));
        $live = $mk('live', null, now()->subDays(300));
        $mOld = $match($expiredOld);
        $mDismissedOld = $match($live, now()->subDays(95));
        $mDismissedNew = $match($expiredNew, now()->subDays(5));
        DB::table('pulse_saves')->insert(['user_id' => $viewer->id, 'saveable_type' => 'signal', 'saveable_id' => $expiredOld, 'created_at' => now(), 'updated_at' => now()]);

        $oldNeed = $this->need($this->owner, ['status' => 'closed', 'expires_at' => now()->subDays(100)]);
        $liveNeed = $this->need($this->owner, ['status' => 'open', 'expires_at' => now()->addDays(5)]);

        $done = $this->purge();

        $this->assertSame(3, $done['signals_deleted'], 'two inserted above and the signal of the long closed need');
        $this->assertFalse(DB::table('pulse_signals')->where('id', $expiredOld)->exists());
        $this->assertFalse(DB::table('pulse_signals')->where('id', $removedOld)->exists());
        $this->assertTrue(DB::table('pulse_signals')->whereIn('id', [$expiredNew, $live])->count() === 2);
        $this->assertFalse(DB::table('pulse_matches')->where('id', $mOld)->exists(), 'its match goes with it');
        $this->assertSame(0, DB::table('pulse_saves')->where('saveable_id', $expiredOld)->where('saveable_type', 'signal')->count());

        $this->assertSame(1, $done['matches_deleted']);
        $this->assertFalse(DB::table('pulse_matches')->where('id', $mDismissedOld)->exists());
        $this->assertTrue(DB::table('pulse_matches')->where('id', $mDismissedNew)->exists());

        $this->assertSame(1, $done['need_texts_cleared']);
        $this->assertNull($oldNeed->fresh()->details);
        $this->assertNotNull($oldNeed->fresh()->title, 'the title stays');
        $this->assertNotNull($liveNeed->fresh()->details);
    }

    public function test_read_notifications_go_after_12_months_and_unread_ones_never(): void
    {
        $n = fn (?string $readAt) => DB::table('notifications')->insertGetId([
            'id' => (string) Str::uuid(), 'type' => PulseNotice::class, 'notifiable_type' => $this->owner->getMorphClass(), 'notifiable_id' => $this->owner->id,
            'data' => '{}', 'read_at' => $readAt, 'created_at' => now()->subYears(2), 'updated_at' => now()->subYears(2),
        ]);

        // insertGetId on a uuid key returns the id string or 0 depending on the driver; count instead.
        $n(now()->subMonths(13)->toDateTimeString());
        $n(now()->subMonths(13)->toDateTimeString());
        $n(now()->subMonths(6)->toDateTimeString());
        $n(null);

        $this->assertSame(2, $this->purge()['notifications_deleted']);
        $this->assertSame(2, DB::table('notifications')->count());
        $this->assertSame(1, DB::table('notifications')->whereNull('read_at')->count());
    }

    public function test_audit_rows_older_than_24_months_lose_the_actor_and_are_never_deleted(): void
    {
        $row = fn ($at) => DB::table('pulse_audit_log')->insertGetId(['actor_id' => $this->owner->id, 'action' => 'test.event', 'ip_hash' => str_repeat('a', 64), 'created_at' => $at]);
        $old = $row(now()->subMonths(25));
        $recent = $row(now()->subMonths(20));

        $this->assertSame(1, $this->purge()['audit_rows_anonymised']);

        $oldRow = DB::table('pulse_audit_log')->find($old);
        $this->assertNull($oldRow->actor_id);
        $this->assertNull($oldRow->ip_hash);
        $this->assertSame('test.event', $oldRow->action, 'the event itself stays');
        $this->assertSame($this->owner->id, (int) DB::table('pulse_audit_log')->find($recent)->actor_id);
        $this->assertSame(0, $this->purge()['audit_rows_anonymised']);

        // The log is still append only: deleting is still refused.
        $this->expectException(\Throwable::class);
        DB::table('pulse_audit_log')->where('id', $old)->delete();
    }

    public function test_finished_invitations_go_30_days_after_they_end(): void
    {
        $inv = fn (array $over) => DB::table('pulse_entity_invitations')->insert($over + [
            'entity_id' => $this->page->id, 'email_hash' => Str::random(64), 'role' => 'editor', 'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(14), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $inv(['expires_at' => now()->subDays(31)]);                       // expired long ago
        $inv(['expires_at' => now()->subDays(5)]);                        // expired recently
        $inv(['declined_at' => now()->subDays(40)]);
        $inv(['revoked_at' => now()->subDays(2)]);
        $inv([]);                                                         // pending

        $this->assertSame(2, $this->purge()['invitations_deleted']);
        $this->assertSame(3, DB::table('pulse_entity_invitations')->count());
    }

    public function test_a_dry_run_changes_nothing_and_the_command_runs_and_is_scheduled_without_overlap(): void
    {
        $this->line('closed', now()->subMonths(30));
        $this->line('declined', now()->subDays(120), ['first_response_at' => null], 1);

        $this->artisan('pulse:retention', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(4, DB::table('pulse_messages')->whereNull('erased_at')->count());
        $this->assertSame(2, PulseConversation::count());

        $this->assertSame(0, Artisan::call('pulse:retention'));
        $this->assertSame(1, PulseConversation::count());

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'pulse:retention'));
        $this->assertNotNull($event);
        $this->assertSame('40 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(120, $event->expiresAt);
    }

    public function test_the_numbers_come_from_config(): void
    {
        $line = $this->line('closed', now()->subMonths(7));
        $this->assertSame(0, $this->purge()['messages_erased']);

        config(['pulse.retention.closed_line_messages_months' => 6]);
        $this->assertSame(3, $this->purge()['messages_erased']);
        $this->assertNotNull($line);
    }
}

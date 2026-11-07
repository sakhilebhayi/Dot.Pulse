<?php

namespace Tests\Feature\Lines;

use App\Models\PulseDoor;
use App\Services\Pulse\Lines\Doors;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class SweepTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    public function test_a_request_untouched_for_fourteen_days_expires_and_the_sender_is_told(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests', 'name' => 'Moyo Plumbing']);
        $c = app(LineService::class)->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(['what' => 'Fix my geyser']), $this->offer($page));

        $this->travel(13)->days();
        $this->artisan('pulse:lines:sweep')->assertSuccessful();
        $this->assertSame('requested', $c->fresh()->status, 'not yet');

        $this->travel(2)->days();
        $this->artisan('pulse:lines:sweep')->assertSuccessful()->expectsOutputToContain('requests closed as expired: 1');

        $c->refresh();
        $this->assertSame('closed', $c->status);
        $this->assertSame('expired', $c->closed_reason);
        $this->assertNotNull($c->closed_at);

        $note = $sender->notifications()->where('data', 'like', '%line_expired%')->first();
        $this->assertNotNull($note, 'never silent');
        $this->assertStringContainsString('Moyo Plumbing did not reply in 14 days', $note->data['title']);
        $this->assertStringContainsString('has closed', $note->data['body']);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'line.expire', 'subject_id' => $c->id]);
    }

    public function test_an_ignored_request_expires_with_the_same_words_so_the_sender_cannot_tell(): void
    {
        $sender = $this->person();
        $other = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests', 'name' => 'Same Name Plumbing']);
        $ignored = app(LineService::class)->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $this->offer($page));
        $unseen = app(LineService::class)->knock($other, ['entity' => $page->id], 'enquiry', $this->card(), $this->offer($page));
        app(LineService::class)->ignoreQuietly($this->responderOf($page), $ignored);

        $this->travel(15)->days();
        $this->artisan('pulse:lines:sweep')->assertSuccessful();

        $a = $sender->notifications()->first()->data;
        $b = $other->notifications()->first()->data;
        $this->assertSame($a['title'], $b['title']);
        $this->assertSame($a['body'], $b['body']);
    }

    public function test_answered_declined_and_open_lines_are_left_alone(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $lines = app(LineService::class);

        $open = $this->openLine($sender, $page);
        $declinedSender = $this->person();
        $declined = $lines->knock($declinedSender, ['entity' => $page->id], 'enquiry', $this->card());
        $lines->decline($owner, $declined);

        $this->travel(30)->days();
        $this->artisan('pulse:lines:sweep')->assertSuccessful();

        $this->assertSame('open', $open->fresh()->status);
        $this->assertSame('declined', $declined->fresh()->status);
    }

    public function test_the_sweep_is_idempotent(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        app(LineService::class)->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $this->offer($page));
        $this->travel(15)->days();

        $this->artisan('pulse:lines:sweep');
        $this->artisan('pulse:lines:sweep')->expectsOutputToContain('requests closed as expired: 0');

        $this->assertSame(1, $sender->notifications()->where('data', 'like', '%line_expired%')->count());
    }

    public function test_doors_lapse_and_take_their_signal_down(): void
    {
        $owner = $this->policy($this->person(), 'context');
        $signal = app(Doors::class)->open($owner, 'Looking for a baker for Saturday', 'day');
        $door = PulseDoor::where('signal_id', $signal->id)->first();

        $this->travel(2)->days();
        $this->artisan('pulse:lines:sweep')->expectsOutputToContain('doors lapsed: 1');

        $this->assertSame('lapsed', $door->fresh()->status);
        $this->assertSame('expired', $signal->fresh()->status);
    }

    public function test_the_command_is_scheduled_hourly_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'pulse:lines:sweep'));

        $this->assertNotNull($event, 'pulse:lines:sweep is scheduled');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}

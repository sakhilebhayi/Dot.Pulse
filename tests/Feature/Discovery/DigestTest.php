<?php

namespace Tests\Feature\Discovery;

use App\Models\PulseNotificationPref;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Digest\DigestBuilder;
use App\Services\Pulse\Discovery\Digest\DigestNotification;
use App\Services\Pulse\Discovery\Digest\DigestSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class DigestTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = $this->dUser();
    }

    private function item(array $attrs = [], float $score = 60.0, ?array $reasons = null): PulseSignal
    {
        $s = $this->dSignal(array_merge(['owner_id' => $this->dUser()->id, 'kind' => 'news', 'subject_type' => 'post', 'title' => 'Item '.rand(1000, 9999), 'published_at' => now()->subHours(3)], $attrs));
        $this->dMatch($this->me, $s, $score, $reasons ?? [['code' => 'topic', 'text' => 'News about plumbing, which you follow.', 'weight' => $score, 'ref' => 'plumbing']]);

        return $s;
    }

    private function sender(): DigestSender
    {
        return app(DigestSender::class);
    }

    public function test_nothing_real_means_no_digest(): void
    {
        $this->assertNull(app(DigestBuilder::class)->build($this->me));

        // A weak match is not worth an email.
        $this->item([], 12.0);
        $this->assertNull(app(DigestBuilder::class)->build($this->me));
    }

    public function test_every_item_carries_its_reason_and_the_links_are_there(): void
    {
        $this->item(['title' => 'Solar geysers explained']);
        $this->item(['title' => 'Plumbing meetup'], 55.0);

        $d = app(DigestBuilder::class)->build($this->me);

        $this->assertSame(2, $d['count']);
        $this->assertSame('2 things on the Floor that fit you', $d['subject']);
        $this->assertSame('Solar geysers explained', $d['items'][0]['title']);
        foreach ($d['items'] as $item) {
            $this->assertNotSame('', $item['reason']);
            $this->assertNotEmpty($item['reasons']);
        }
        $this->assertStringContainsString('/floor', $d['floor_url']);
        $this->assertStringContainsString('signature=', $d['unsubscribe_url']);
        $this->assertStringNotContainsString('!', $d['subject'].$d['intro'], 'no exclamation marks in the voice');
    }

    public function test_seen_dismissed_saved_blocked_and_unavailable_things_are_left_out(): void
    {
        $keep = $this->item(['title' => 'Keep me']);
        $seen = $this->item(['title' => 'Seen']);
        DB::table('pulse_matches')->where('signal_id', $seen->id)->update(['seen_at' => now()]);
        $dismissed = $this->item(['title' => 'Dismissed']);
        DB::table('pulse_matches')->where('signal_id', $dismissed->id)->update(['dismissed_at' => now()]);
        $saved = $this->item(['title' => 'Saved']);
        DB::table('pulse_saves')->insert(['user_id' => $this->me->id, 'saveable_type' => 'signal', 'saveable_id' => $saved->id, 'created_at' => now(), 'updated_at' => now()]);
        $blocked = $this->dUser();
        $this->dBlock($this->me, $blocked);
        $this->item(['title' => 'From blocked', 'owner_id' => $blocked->id]);
        $this->item(['title' => 'Held', 'status' => 'held']);
        $this->item(['title' => 'Removed', 'status' => 'removed']);
        $this->item(['title' => 'Expired', 'expires_at' => now()->subMinute()]);
        $this->item(['title' => 'No reasons'], 50.0, []);

        $d = app(DigestBuilder::class)->build($this->me);

        $this->assertSame(['Keep me'], array_column($d['items'], 'title'));
    }

    public function test_only_what_is_new_since_the_last_digest_goes_in_the_next_one(): void
    {
        $this->dPrefs($this->me, ['last_digest_at' => now()->subHours(5)]);
        $this->item(['title' => 'Old news', 'published_at' => now()->subHours(9)]);
        $this->item(['title' => 'Fresh news', 'published_at' => now()->subHours(2)]);

        $this->assertSame(['Fresh news'], array_column(app(DigestBuilder::class)->build($this->me)['items'], 'title'));
    }

    public function test_it_is_short_and_not_a_wall_of_one_kind(): void
    {
        foreach (range(1, 10) as $i) {
            $this->item(['title' => "News {$i}", 'kind' => 'news'], 90 - $i);
        }
        $this->item(['title' => 'An ask', 'kind' => 'ask'], 10 + 40);

        $d = app(DigestBuilder::class)->build($this->me);

        $this->assertLessThanOrEqual(6, $d['count']);
        $this->assertLessThanOrEqual(3, collect($d['items'])->where('kind', 'news')->count());
        $this->assertGreaterThan(0, $d['more']);
    }

    public function test_no_preference_row_means_no_digest(): void
    {
        $this->item();
        Notification::fake();

        $this->assertFalse($this->sender()->send($this->me));
        Notification::assertNothingSent();
    }

    public function test_preferences_are_honoured(): void
    {
        $this->item();
        Notification::fake();

        foreach ([
            ['digest' => false],
            ['frequency' => 'instant'],
            ['frequency' => 'off'],
            ['channels' => ['in_app' => true, 'email' => false]],
            ['categories' => ['digest' => false]],
            ['categories' => ['match' => false]],
        ] as $override) {
            DB::table('pulse_notification_prefs')->delete();
            $this->dPrefs($this->me, $override);

            $this->assertFalse($this->sender()->send($this->me), json_encode($override));
        }

        Notification::assertNothingSent();

        DB::table('pulse_notification_prefs')->delete();
        $this->dPrefs($this->me);
        $this->assertTrue($this->sender()->send($this->me));
        Notification::assertSentTo($this->me, DigestNotification::class);
        $this->assertNotNull(PulseNotificationPref::first()->last_digest_at);
    }

    public function test_it_is_sent_once_per_slot(): void
    {
        $this->item();
        $this->dPrefs($this->me);
        Notification::fake();

        $this->assertTrue($this->sender()->send($this->me));
        $this->item(['title' => 'Another']);
        $this->assertFalse($this->sender()->send($this->me));

        Notification::assertSentToTimes($this->me, DigestNotification::class, 1);
    }

    public function test_the_digest_hour_is_in_the_persons_timezone_with_a_grace_window(): void
    {
        $this->dPrefs($this->me, ['digest_hour' => '07:30', 'timezone' => 'Africa/Johannesburg']);
        $prefs = PulseNotificationPref::first();
        $sender = $this->sender();

        // 07:30 in Johannesburg is 05:30 UTC.
        $this->assertFalse($sender->dueFor($prefs, now()->setDate(2026, 11, 10)->setTime(5, 0)), 'before the hour');
        $this->assertTrue($sender->dueFor($prefs, now()->setDate(2026, 11, 10)->setTime(5, 45)), 'in the hour');
        $this->assertTrue($sender->dueFor($prefs, now()->setDate(2026, 11, 10)->setTime(12, 0)), 'a missed run is caught up inside the grace window');
        $this->assertFalse($sender->dueFor($prefs, now()->setDate(2026, 11, 10)->setTime(20, 0)), 'too late: wait for tomorrow');

        $prefs->forceFill(['last_digest_at' => now()->setDate(2026, 11, 10)->setTime(5, 40)])->save();
        $this->assertFalse($sender->dueFor($prefs->fresh(), now()->setDate(2026, 11, 10)->setTime(6, 40)), 'already sent for this slot');
        $this->assertTrue($sender->dueFor($prefs->fresh(), now()->setDate(2026, 11, 11)->setTime(5, 45)), 'tomorrow is a new slot');
    }

    public function test_a_different_timezone_moves_the_slot(): void
    {
        $this->dPrefs($this->me, ['digest_hour' => '07:30', 'timezone' => 'America/New_York']);
        $prefs = PulseNotificationPref::first();

        // 07:30 in New York in November is 12:30 UTC.
        $this->assertFalse($this->sender()->dueFor($prefs, now()->setDate(2026, 11, 10)->setTime(5, 45)));
        $this->assertTrue($this->sender()->dueFor($prefs, now()->setDate(2026, 11, 10)->setTime(12, 45)));
    }

    public function test_weekly_goes_on_monday_only(): void
    {
        $this->dPrefs($this->me, ['frequency' => 'weekly']);
        $prefs = PulseNotificationPref::first();

        $this->assertFalse($this->sender()->dueFor($prefs, now()->setDate(2026, 11, 10)->setTime(5, 45)), 'a Tuesday');
        $this->assertTrue($this->sender()->dueFor($prefs, now()->setDate(2026, 11, 9)->setTime(5, 45)), 'a Monday');
    }

    public function test_quiet_hours_hold_it_back_until_they_end(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(5, 45)); // 07:45 in Johannesburg: still quiet
        $this->item();
        $this->dPrefs($this->me, ['channels' => ['in_app' => true, 'email' => true, 'quiet_hours' => ['start' => '22:00', 'end' => '08:00']]]);
        Notification::fake();

        $this->assertFalse($this->sender()->send($this->me));

        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(6, 30)); // 08:30: quiet is over
        $this->assertTrue($this->sender()->send($this->me));
        $this->travelBack();
    }

    public function test_the_mail_is_plain_well_formed_and_one_click_to_stop(): void
    {
        config(['mail.default' => 'array']);
        $this->item(['title' => 'Plumbing meetup on Thursday', 'excerpt' => 'Bring a business card.']);
        $this->dPrefs($this->me);

        $this->assertTrue($this->sender()->send($this->me));

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages->first()->getOriginalMessage();
        $html = $mail->getHtmlBody();
        $text = $mail->getTextBody();

        $this->assertSame('1 thing on the Floor that fits you', $mail->getSubject());
        $this->assertStringContainsString('Plumbing meetup on Thursday', $html);
        $this->assertStringContainsString('Why you are seeing this', $html);
        $this->assertStringContainsString('News about plumbing, which you follow.', $html);
        $this->assertStringContainsString('Stop the digest', $html);
        $this->assertStringContainsString('Why you are seeing this: News about plumbing, which you follow.', $text);
        $this->assertStringContainsString('Stop the digest', $text);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertTrue($mail->getHeaders()->has('List-Unsubscribe'));
        $this->assertSame('List-Unsubscribe=One-Click', $mail->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());

        preg_match('/<(http[^>]+unsubscribe[^>]+)>/', $mail->getHeaders()->get('List-Unsubscribe')->getBodyAsString(), $m);
        $this->assertNotEmpty($m);
        $link = html_entity_decode($m[1]);

        // The signed link works with no sign-in, on GET and on POST, and only affects the digest.
        $this->get($link)->assertOk()->assertSee('The digest has stopped');
        $this->assertFalse((bool) PulseNotificationPref::first()->digest);
        DB::table('pulse_notification_prefs')->update(['digest' => true]);
        $this->post($link)->assertOk();
        $this->assertFalse((bool) PulseNotificationPref::first()->digest);
        $this->assertSame('daily', PulseNotificationPref::first()->frequency);
    }

    public function test_the_unsubscribe_link_cannot_be_forged_or_aimed_at_someone_else(): void
    {
        $this->dPrefs($this->me);
        $other = $this->dUser();
        $this->dPrefs($other);

        $this->get(route('pulse.digest.unsubscribe', $this->me))->assertForbidden();

        $link = app(DigestBuilder::class)->unsubscribeUrl($this->me);
        $this->get(str_replace('/'.$this->me->id.'?', '/'.$other->id.'?', $link))->assertForbidden();
        $this->assertTrue((bool) PulseNotificationPref::where('user_id', $other->id)->first()->digest);
    }

    public function test_the_command_sends_only_to_people_who_are_due_and_have_something_real(): void
    {
        Notification::fake();
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(5, 45));
        $this->item();
        $this->dPrefs($this->me);
        $nothing = $this->dUser();
        $this->dPrefs($nothing);
        $off = $this->dUser();
        $this->dPrefs($off, ['digest' => false]);

        $this->artisan('pulse:digest')->assertSuccessful();
        $this->travelBack();

        Notification::assertSentTo($this->me, DigestNotification::class);
        Notification::assertNotSentTo($nothing, DigestNotification::class);
        Notification::assertNotSentTo($off, DigestNotification::class);
    }
}

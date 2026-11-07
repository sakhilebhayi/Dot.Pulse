<?php

namespace Tests\Feature\AcceptanceB;

use App\Models\PulseNotificationPref;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Digest\DigestSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

/**
 * Found by the browser acceptance run (suite B, criterion 9), fixed at the root:
 *  - the plain text part of the digest escaped "&" as "&amp;", which broke the signed unsubscribe link;
 *  - a person who asked for the daily summary while conversation email is "Right away" (what onboarding saves) never got one;
 *  - the settings page offered a Matches switch that controlled nothing.
 */
class NotificationControlRegressionTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private function withOneMatch(User $u): void
    {
        $s = $this->dSignal(['owner_id' => $this->dUser()->id, 'kind' => 'news', 'subject_type' => 'post', 'title' => 'Plumbing meetup', 'published_at' => now()->subHours(3)]);
        $this->dMatch($u, $s, 60.0, [['code' => 'topic', 'text' => 'News about plumbing, which you follow.', 'weight' => 60, 'ref' => 'plumbing']]);
    }

    public function test_the_plain_text_digest_carries_a_working_unsubscribe_link(): void
    {
        $me = $this->dUser();
        $this->dPrefs($me);
        $this->withOneMatch($me);
        config(['mail.default' => 'array']);

        $this->assertTrue(app(DigestSender::class)->send($me));

        $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $text = $mail->getTextBody();
        $this->assertStringNotContainsString('&amp;', $text, 'a plain text part must not be HTML escaped');
        preg_match('/Stop the digest: (\S+)/', $text, $m);
        $this->assertNotEmpty($m);
        $this->get($m[1])->assertOk()->assertSee('The digest has stopped');
    }

    public function test_the_summary_is_sent_to_someone_whose_conversation_email_is_right_away(): void
    {
        $me = $this->dUser();
        $this->dPrefs($me, ['frequency' => 'instant']);
        $this->withOneMatch($me);
        Notification::fake();

        $this->assertTrue(app(DigestSender::class)->send($me), 'onboarding saves frequency=instant with the summary on');
        Notification::assertSentTo($me, \App\Services\Pulse\Discovery\Digest\DigestNotification::class);
    }

    public function test_the_digest_command_picks_up_instant_people_when_their_hour_comes(): void
    {
        $me = $this->dUser();
        $this->dPrefs($me, ['frequency' => 'instant', 'digest_hour' => '00:00', 'timezone' => 'UTC']);
        $this->withOneMatch($me);
        Notification::fake();
        $this->travelTo(now('UTC')->setTime(3, 0));

        $this->artisan('pulse:digest')->assertSuccessful();
        Notification::assertSentTo($me, \App\Services\Pulse\Discovery\Digest\DigestNotification::class);
    }

    public function test_off_still_means_no_summary(): void
    {
        $me = $this->dUser();
        $this->dPrefs($me, ['frequency' => 'off']);
        $this->withOneMatch($me);
        Notification::fake();

        $this->assertFalse(app(DigestSender::class)->send($me));
    }

    public function test_the_settings_page_has_no_matches_switch_because_nothing_sends_one(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        DB::table('pulse_profiles')->insert(['user_id' => $me->id, 'age_confirmed_at' => now(), 'onboarded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($me)->get('/me/notifications')
            ->assertOk()
            ->assertDontSee('email-matches')
            ->assertDontSee('inapp-matches')
            ->assertSee('Send me a daily summary');

        $this->assertDatabaseMissing('pulse_notification_prefs', ['user_id' => $me->id]);
        $this->assertSame(0, PulseNotificationPref::count());
        $this->assertSame(0, PulseSignal::count());
    }
}

<?php

namespace Tests\Feature\Lines;

use App\Livewire\Pulse\NotificationBell;
use App\Models\User;
use App\Notifications\NewFollower;
use App\Notifications\PulseNotice;
use App\Services\Pulse\Lines\NotificationPrefs;
use App\Services\Pulse\Lines\NotificationPresenter;
use App\Services\Pulse\Lines\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class NotifierTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function notifier(): Notifier
    {
        return app(Notifier::class);
    }

    private function prefs(): NotificationPrefs
    {
        return app(NotificationPrefs::class);
    }

    private function payload(array $over = []): array
    {
        return $over + ['type' => 'line_request', 'title' => 'Moyo sent a request', 'body' => 'About: Geyser repair'];
    }

    /** @return list<list<string>> the channel lists of every PulseNotice sent to the user */
    private function sentChannels(User $u): array
    {
        return Notification::sent($u, PulseNotice::class)->map(fn ($n) => $n->channels)->values()->all();
    }

    public function test_a_transactional_event_always_writes_the_database_notification_and_mails_by_default(): void
    {
        Notification::fake();
        $u = $this->person();

        $this->notifier()->notify($u, 'lines', $this->payload());

        $this->assertEqualsCanonicalizing([['database'], ['mail']], $this->sentChannels($u));
    }

    public function test_the_database_row_has_a_category_and_a_resolved_url(): void
    {
        $u = $this->person();
        Route::get('/lines/{conversation}', fn () => 'x')->name('lines.show');
        app('router')->getRoutes()->refreshNameLookups();

        $this->notifier()->notify($u, 'lines', $this->payload(['route' => ['name' => 'lines.show', 'params' => ['conversation' => 5]]]));

        $row = $u->notifications()->first();
        $this->assertSame(PulseNotice::class, $row->type);
        $this->assertSame('lines', $row->data['category']);
        $this->assertStringEndsWith('/lines/5', $row->data['url']);
        $this->assertTrue(NotificationPresenter::handles($row->data));
        $this->assertSame('Moyo sent a request', NotificationPresenter::present($row)['lead']);
    }

    public function test_email_follows_preferences_but_the_in_app_copy_does_not(): void
    {
        Notification::fake();

        $noEmail = $this->person();
        $this->prefs()->update($noEmail, ['channels' => ['email' => false]]);
        $this->notifier()->notify($noEmail, 'lines', $this->payload());
        $this->assertSame([['database']], $this->sentChannels($noEmail));

        $noLinesMail = $this->person();
        $this->prefs()->update($noLinesMail, ['categories' => ['lines' => ['email' => false]]]);
        $this->notifier()->notify($noLinesMail, 'lines', $this->payload());
        $this->notifier()->notify($noLinesMail, 'outcomes', $this->payload(['type' => 'outcome_confirmed']));
        $this->assertEqualsCanonicalizing([['database'], ['database'], ['mail']], $this->sentChannels($noLinesMail));

        $off = $this->person();
        $this->prefs()->update($off, ['frequency' => 'off']);
        $this->notifier()->notify($off, 'lines', $this->payload());
        $this->assertSame([['database']], $this->sentChannels($off));
    }

    public function test_daily_and_weekly_people_get_no_instant_mail_except_system(): void
    {
        Notification::fake();
        $u = $this->person();
        $this->prefs()->update($u, ['frequency' => 'daily']);

        $this->notifier()->notify($u, 'lines', $this->payload());
        $this->assertSame([['database']], $this->sentChannels($u));

        $this->notifier()->notify($u, 'system', $this->payload(['type' => 'security']));
        $this->assertCount(3, $this->sentChannels($u), 'system mail still goes');
    }

    public function test_quiet_hours_hold_the_email_not_the_in_app_notification(): void
    {
        Notification::fake();
        $u = $this->person();
        $this->prefs()->update($u, ['timezone' => 'Africa/Johannesburg', 'quiet_start' => '22:00', 'quiet_end' => '06:00']);

        $this->travelTo(now()->setTimezone('UTC')->setTime(21, 0)); // 23:00 in Johannesburg
        $this->assertTrue($this->prefs()->quietNow($u));
        $this->notifier()->notify($u, 'lines', $this->payload());
        $this->assertSame([['database']], $this->sentChannels($u));

        $this->travelTo(now()->setTimezone('UTC')->setTime(8, 0)); // 10:00
        $this->assertFalse($this->prefs()->quietNow($u));
        $this->notifier()->notify($u, 'lines', $this->payload(['title' => 'Later']));
        $this->assertCount(3, $this->sentChannels($u));
    }

    public function test_quiet_hours_that_do_not_wrap_midnight(): void
    {
        $u = $this->person();
        $this->prefs()->update($u, ['timezone' => 'UTC', 'quiet_start' => '13:00', 'quiet_end' => '14:00']);

        $this->travelTo(now()->setTimezone('UTC')->setTime(13, 30));
        $this->assertTrue($this->prefs()->quietNow($u));
        $this->travelTo(now()->setTimezone('UTC')->setTime(14, 0));
        $this->assertFalse($this->prefs()->quietNow($u));
        $this->prefs()->update($u, ['quiet_start' => null, 'quiet_end' => null]);
        $this->assertFalse($this->prefs()->quietNow($u));
    }

    public function test_opt_in_categories_respect_the_in_app_preference_but_transactional_ones_do_not(): void
    {
        $u = $this->person();
        $this->prefs()->update($u, ['categories' => [
            'matches' => ['in_app' => false],
            'lines' => ['in_app' => false],
        ]]);

        $this->notifier()->notify($u, 'matches', $this->payload(['type' => 'match']));
        $this->assertSame(0, $u->notifications()->count(), 'a match the person turned off is not delivered');

        $this->notifier()->notify($u, 'lines', $this->payload());
        $this->assertSame(1, $u->notifications()->count(), 'a reply to your own enquiry is always shown');
    }

    public function test_the_digest_category_never_sends_instant_mail(): void
    {
        Notification::fake();
        $u = $this->person();

        $this->notifier()->notify($u, 'digest', $this->payload(['type' => 'digest']));

        $this->assertSame([['database']], $this->sentChannels($u));
    }

    public function test_unknown_categories_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->notifier()->notify($this->person(), 'marketing', $this->payload());
    }

    public function test_notifications_with_a_coalesce_key_fold_together_until_read(): void
    {
        $u = $this->person();

        foreach (range(1, 4) as $i) {
            $this->notifier()->notify($u, 'lines', $this->payload(['type' => 'line_message', 'coalesce_key' => 'line:9', 'body' => "message {$i}"]));
        }
        $this->assertSame(1, $u->notifications()->count());
        $this->assertSame(4, $u->notifications()->first()->data['count']);
        $this->assertSame('message 4', $u->notifications()->first()->data['body']);

        $u->unreadNotifications->markAsRead();
        $this->notifier()->notify($u, 'lines', $this->payload(['type' => 'line_message', 'coalesce_key' => 'line:9']));
        $this->assertSame(2, $u->notifications()->count(), 'once read, the next one is new');

        $this->notifier()->notify($u, 'lines', $this->payload(['type' => 'line_message', 'coalesce_key' => 'line:10']));
        $this->assertSame(3, $u->notifications()->count(), 'a different line is its own notification');
    }

    public function test_a_mail_failure_never_undoes_the_in_app_notification(): void
    {
        $u = $this->person();
        Event::listen(NotificationSending::class, function ($event) {
            if ($event->channel === 'mail') {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->notifier()->notify($u, 'lines', $this->payload());

        $this->assertSame(1, $u->notifications()->count());
    }

    public function test_preferences_defaults_are_created_on_first_read_and_validated_on_update(): void
    {
        $u = $this->person();
        $p = $this->prefs()->get($u);

        $this->assertTrue($p->channels['in_app']);
        $this->assertSame('instant', $p->frequency);
        $this->assertSame('07:30', $p->digest_hour);
        $this->assertTrue($this->prefs()->allowed($u, 'lines', 'email'));
        $this->assertFalse($this->prefs()->allowed($u, 'marketing', 'email'));
        $this->assertFalse($this->prefs()->allowed($u, 'lines', 'sms'));

        foreach ([['frequency' => 'hourly'], ['digest_hour' => '25:00'], ['timezone' => 'Mars/Olympus'], ['quiet_start' => '7pm']] as $bad) {
            try {
                $this->prefs()->update($u, $bad);
                $this->fail('refused');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        $this->prefs()->update($u, ['digest' => false, 'digest_hour' => '06:15', 'categories' => ['matches' => false]]);
        $p->refresh();
        $this->assertFalse($p->digest);
        $this->assertSame('06:15', $p->digest_hour);
        $this->assertFalse($this->prefs()->allowed($u, 'matches', 'in_app'));
        $this->assertFalse($this->prefs()->allowed($u, 'matches', 'email'));
        $this->assertTrue($this->prefs()->allowed($u, 'lines', 'in_app'));
    }

    public function test_allowed_without_a_stored_row_uses_defaults_and_writes_nothing(): void
    {
        $u = $this->person();

        $this->assertTrue($this->prefs()->allowed($u, 'lines', 'email'));
        $this->assertDatabaseMissing('pulse_notification_prefs', ['user_id' => $u->id]);
    }

    public function test_the_existing_bell_keeps_working_with_old_and_new_notifications(): void
    {
        $u = $this->person();
        $follower = $this->person();
        $u->notify(new NewFollower($follower));
        $this->notifier()->notify($u, 'lines', $this->payload());

        $this->actingAs($u);
        $bell = Livewire::test(NotificationBell::class);

        $this->assertSame(2, $bell->instance()->unreadCount);
        $rows = $bell->instance()->recent;
        $this->assertCount(2, $rows);

        $old = $rows->first(fn ($n) => $n->type === NewFollower::class);
        $this->assertSame($follower->name, $bell->instance()->present($old)['lead']);

        $new = $rows->first(fn ($n) => $n->type === PulseNotice::class);
        $this->assertIsArray($bell->instance()->present($new), 'the old switch does not crash on the new payloads');
        $this->assertSame('Moyo sent a request', NotificationPresenter::present($new)['lead']);

        $bell->call('markAllRead');
        $this->assertSame(0, $u->fresh()->unreadNotifications()->count());
    }
}

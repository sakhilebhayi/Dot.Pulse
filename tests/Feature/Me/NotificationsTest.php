<?php

namespace Tests\Feature\Me;

use App\Livewire\Pulse\Me\NotificationList;
use App\Livewire\Pulse\Me\NotificationSettings;
use App\Livewire\Pulse\NotificationBell;
use App\Models\PulseNotificationPref;
use App\Notifications\NewFollower;
use App\Services\Pulse\Lines\Notifier;
use Livewire\Livewire;

class NotificationsTest extends MeTestCase
{
    private function notify($u, array $payload = []): void
    {
        app(Notifier::class)->notify($u, 'lines', $payload + ['type' => 'line_request', 'title' => 'Moyo sent a request', 'body' => 'About: Geyser repair', 'route' => ['name' => 'me.privacy']]);
    }

    public function test_the_page_renders_without_the_welcome_flow_and_without_creating_a_preferences_row(): void
    {
        $u = $this->newcomer();

        $this->actingAs($u)->get('/me/notifications')->assertOk()->assertSee('What happened')->assertSee('What reaches you');

        $this->assertDatabaseMissing('pulse_notification_prefs', ['user_id' => $u->id]);
    }

    public function test_pulse_notifications_use_the_presenter_and_old_ones_keep_their_wording(): void
    {
        $u = $this->member();
        $follower = $this->person();
        $u->notify(new NewFollower($follower));
        $this->notify($u);

        Livewire::actingAs($u)->test(NotificationList::class)
            ->assertSee('Moyo sent a request')
            ->assertSee('About: Geyser repair')
            ->assertSee($follower->name)
            ->assertSee('started following you');
    }

    public function test_mark_read_unread_and_all(): void
    {
        $u = $this->member();
        $this->notify($u);
        $this->notify($u, ['title' => 'Second', 'coalesce_key' => null]);
        $id = $u->notifications()->latest()->first()->id;

        $c = Livewire::actingAs($u)->test(NotificationList::class)->call('markRead', $id);
        $this->assertNotNull($u->notifications()->find($id)->read_at);
        $c->call('markUnread', $id);
        $this->assertNull($u->notifications()->find($id)->read_at);
        $c->call('markAllRead');
        $this->assertSame(0, $u->unreadNotifications()->count());
    }

    public function test_a_person_cannot_touch_someone_elses_notification(): void
    {
        $a = $this->member();
        $b = $this->member();
        $this->notify($a);
        $id = $a->notifications()->first()->id;

        Livewire::actingAs($b)->test(NotificationList::class)->call('markRead', $id)->call('open', $id);

        $this->assertNull($a->notifications()->first()->read_at);
    }

    public function test_opening_a_notification_marks_it_read_and_follows_a_same_site_link_only(): void
    {
        $u = $this->member();
        $this->notify($u);
        $id = $u->notifications()->first()->id;

        Livewire::actingAs($u)->test(NotificationList::class)->call('open', $id)->assertRedirect(route('me.privacy'));
        $this->assertNotNull($u->notifications()->first()->read_at);

        $u->notifications()->delete();
        app(Notifier::class)->notify($u, 'lines', ['type' => 'x', 'title' => 'Elsewhere', 'url' => 'https://evil.example.test/steal']);
        $bad = $u->notifications()->first()->id;
        Livewire::actingAs($u)->test(NotificationList::class)->call('open', $bad)->assertNoRedirect();
    }

    public function test_unread_filter_and_show_older(): void
    {
        $u = $this->member();
        foreach (range(1, 25) as $i) {
            $this->notify($u, ['title' => "Note {$i}"]);
        }
        $u->notifications()->latest()->first()->markAsRead();

        $c = Livewire::actingAs($u)->test(NotificationList::class);
        $this->assertCount(21, $c->get('notifications'), 'one extra row tells the page there is more');
        $c->assertSee('Show older')->call('more')->assertDontSee('Show older');
        $c->set('unreadOnly', true);
        $this->assertCount(24, $c->get('notifications'));
    }

    public function test_the_empty_states_are_honest(): void
    {
        Livewire::actingAs($this->member())->test(NotificationList::class)->assertSee('Nothing yet.');
    }

    public function test_preferences_start_from_the_defaults_with_the_summary_off_and_write_nothing_on_load(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(NotificationSettings::class)
            ->assertSet('digest', false)
            ->assertSet('frequency', 'instant')
            ->assertSet('timezone', 'Africa/Johannesburg')
            ->assertSet('inApp.lines', true);

        $this->assertDatabaseMissing('pulse_notification_prefs', ['user_id' => $u->id]);
    }

    public function test_preferences_persist_through_notification_prefs(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(NotificationSettings::class)
            ->set('email.lines', false)
            ->set('email.matches', true)
            ->set('inApp.matches', false)
            ->set('frequency', 'daily')
            ->set('digest', true)
            ->set('digestHour', '06:00')
            ->set('timezone', 'Europe/London')
            ->set('quietStart', '22:00')
            ->set('quietEnd', '06:30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $pref = PulseNotificationPref::query()->where('user_id', $u->id)->firstOrFail();
        $this->assertSame('daily', $pref->frequency);
        $this->assertTrue($pref->digest);
        $this->assertSame('06:00', $pref->digest_hour);
        $this->assertSame('Europe/London', $pref->timezone);
        $this->assertSame('22:00', $pref->quiet_start);
        $this->assertFalse($pref->categories['lines']['email']);
        $this->assertTrue($pref->categories['matches']['email']);
        $this->assertFalse($pref->categories['matches']['in_app']);
        $this->assertTrue($pref->categories['digest']['email'], 'the digest toggle drives the digest email');

        Livewire::actingAs($u)->test(NotificationSettings::class)
            ->assertSet('frequency', 'daily')->assertSet('digest', true)->assertSet('email.lines', false)->assertSet('quietEnd', '06:30');
    }

    public function test_transactional_in_app_and_system_email_cannot_be_switched_off(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(NotificationSettings::class)
            ->set('inApp.lines', false)->set('inApp.system', false)->set('email.system', false)->call('save');

        $pref = PulseNotificationPref::query()->where('user_id', $u->id)->firstOrFail();
        $this->assertTrue($pref->categories['lines']['in_app']);
        $this->assertTrue($pref->categories['system']['in_app']);
        $this->assertTrue($pref->categories['system']['email']);
    }

    public function test_time_zone_and_clock_values_are_validated(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(NotificationSettings::class)
            ->set('timezone', 'Nowhere/Land')->call('save')->assertHasErrors(['timezone'])
            ->set('timezone', 'Africa/Johannesburg')->set('digestHour', '7am')->call('save')->assertHasErrors(['digestHour'])
            ->set('digestHour', '07:30')->set('quietStart', '25:00')->set('quietEnd', '06:00')->call('save')->assertHasErrors(['quietStart'])
            ->set('quietStart', '22:00')->set('frequency', 'hourly')->call('save')->assertHasErrors(['frequency']);

        $this->assertDatabaseMissing('pulse_notification_prefs', ['user_id' => $u->id]);
    }

    public function test_quiet_hours_need_both_ends_or_neither(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(NotificationSettings::class)
            ->set('quietStart', '22:00')->set('quietEnd', null)->call('save')->assertHasErrors(['quietEnd'])
            ->call('clearQuiet')->call('save')->assertHasNoErrors();

        $this->assertNull(PulseNotificationPref::query()->where('user_id', $u->id)->first()->quiet_start);
    }

    public function test_the_device_time_zone_is_taken_only_when_valid(): void
    {
        Livewire::actingAs($this->member())->test(NotificationSettings::class)
            ->call('useDeviceZone', 'Moon/Base')->assertSet('timezone', 'Africa/Johannesburg')
            ->call('useDeviceZone', 'Africa/Lagos')->assertSet('timezone', 'Africa/Lagos');
    }

    public function test_the_bell_component_reads_the_shared_presenter(): void
    {
        $u = $this->member();
        $this->notify($u);

        $bell = Livewire::actingAs($u)->test(NotificationBell::class);
        $this->assertSame(1, $bell->instance()->unreadCount);
        $this->assertSame('Moyo sent a request', $bell->instance()->present($u->notifications()->first())['lead']);
        $bell->assertSee('1 unread')->call('markAllRead');
        $this->assertSame(0, $u->unreadNotifications()->count());
    }

    public function test_the_shell_shows_a_badge_link_to_the_page_instead_of_the_old_dropdown(): void
    {
        $this->actingAs($this->member())->get('/me/notifications')
            ->assertOk()
            ->assertSee(route('me.notifications'), false)
            ->assertSee('data-pulse-badge="notifications"', false);
    }
}

<?php

namespace Tests\Feature\Me;

use App\Http\Controllers\Pulse\PrivacyExportController;
use App\Livewire\Pulse\Me\Privacy;
use App\Notifications\StepUpCode;
use App\Services\Pulse\Lines\StepUp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/** Downloading and erasing your data ask for a typed phrase and, where mail is set up, a one time emailed code. */
class StepUpTest extends MeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.privacy.export_needs_confirmation' => true]);
    }

    /** The code Pulse just emailed, read back from the fake. */
    private function emailedCode($user): string
    {
        $code = null;
        Notification::assertSentTo($user, StepUpCode::class, function (StepUpCode $n) use (&$code) {
            $code = (new \ReflectionProperty($n, 'code'))->getValue($n);

            return true;
        });

        return $code;
    }

    public function test_the_file_is_not_handed_out_without_the_confirmation(): void
    {
        $me = $this->member();

        $this->actingAs($me)->get('/me/privacy/export')->assertRedirect(route('me.privacy'));
        $this->actingAs($me)->withSession([PrivacyExportController::GRANT => now()->subMinute()->getTimestamp()])->get('/me/privacy/export')->assertRedirect(route('me.privacy'));
    }

    public function test_without_mail_the_typed_phrase_alone_opens_a_five_minute_download_that_works_once(): void
    {
        config(['mail.default' => 'log']);
        $me = $this->member();

        $c = Livewire::actingAs($me)->test(Privacy::class)->assertDontSee('6 digit code')
            ->call('startExport')->set('confirmation', 'download')->call('export')->assertHasErrors(['confirmation']);

        $c->set('confirmation', ' Download My Data ')->call('export')->assertHasNoErrors()->assertRedirect(route('me.export'));

        $grant = session(PrivacyExportController::GRANT);
        $this->assertGreaterThan(now()->getTimestamp(), $grant);

        $this->withSession([PrivacyExportController::GRANT => $grant])->get('/me/privacy/export')->assertOk();
        $this->get('/me/privacy/export')->assertRedirect(route('me.privacy'));
    }

    public function test_with_mail_a_code_is_emailed_and_must_be_entered_once(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $me = $this->member();

        $c = Livewire::actingAs($me)->test(Privacy::class)->call('startExport')->assertSee('6 digit code')
            ->set('confirmation', 'download my data')->call('export')->assertHasErrors(['code']);
        $this->assertNull(session(PrivacyExportController::GRANT));

        $c->call('sendCode', 'export');
        $code = $this->emailedCode($me);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $c->set('code', '000000')->call('export')->assertHasErrors(['code']);
        $c->set('code', $code)->call('export')->assertHasNoErrors()->assertRedirect(route('me.export'));

        // Used up: the same code does nothing the second time.
        Livewire::actingAs($me)->test(Privacy::class)->call('startExport')->set('confirmation', 'download my data')->set('code', $code)->call('export')->assertHasErrors(['code']);
    }

    public function test_a_code_for_download_does_not_erase_and_expires_after_ten_minutes(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $me = $this->member(['headline' => 'Keep me']);

        $c = Livewire::actingAs($me)->test(Privacy::class)->call('sendCode', 'export');
        $code = $this->emailedCode($me);

        $c->call('startErase')->set('confirmation', 'erase my data')->set('code', $code)->call('erase')->assertHasErrors(['code']);
        $this->assertSame('Keep me', $this->profileOf($me)->headline);

        $this->travel(11)->minutes();
        $c->call('startExport')->set('confirmation', 'download my data')->set('code', $code)->call('export')->assertHasErrors(['code']);
    }

    public function test_erasing_needs_the_code_when_mail_is_configured_and_five_wrong_tries_burn_it(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $me = $this->member(['headline' => 'Erase me']);

        $c = Livewire::actingAs($me)->test(Privacy::class)->call('startErase')->call('sendCode', 'erase');
        $code = $this->emailedCode($me);

        $c->set('confirmation', 'erase my data')->set('code', '111111');

        foreach (range(1, 5) as $i) {
            $c->call('erase')->assertHasErrors(['code']);
        }

        $c->set('code', $code)->call('erase')->assertHasErrors(['code']); // burned: ask for a new one
        $this->assertSame('Erase me', $this->profileOf($me)->headline);

        RateLimiter::clear('pulse-stepup-send:erase:'.$me->id);
        $c->call('sendCode', 'erase');
        Notification::assertSentToTimes($me, StepUpCode::class, 2);
    }

    public function test_sending_codes_is_limited_and_a_code_is_never_stored_in_clear(): void
    {
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $me = $this->member();
        $c = Livewire::actingAs($me)->test(Privacy::class);

        foreach (range(1, 3) as $i) {
            $c->call('sendCode', 'export')->assertHasNoErrors();
        }
        $c->call('sendCode', 'export')->assertHasErrors(['code']);

        $code = $this->emailedCode($me);
        $this->assertStringNotContainsString($code, json_encode(Cache::get('pulse:stepup:export:'.$me->id)));
    }

    public function test_the_setting_can_force_the_code_on_or_off(): void
    {
        config(['mail.default' => 'log', 'pulse.privacy.step_up_code' => 'on']);
        $this->assertTrue(StepUp::codeRequired());

        config(['mail.default' => 'smtp', 'pulse.privacy.step_up_code' => 'off']);
        $this->assertFalse(StepUp::codeRequired());

        config(['pulse.privacy.step_up_code' => 'auto', 'mail.default' => 'array']);
        $this->assertFalse(StepUp::codeRequired());
    }
}

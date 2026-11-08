<?php

namespace Tests\Feature\Polish;

use App\Livewire\Pulse\Business\EntityForm;
use App\Livewire\Pulse\Business\OfferManager;
use App\Livewire\Pulse\Business\RoleManager;
use App\Livewire\Pulse\Business\TeamManager;
use App\Livewire\Pulse\CreatePost;
use App\Livewire\Pulse\Floor\Door;
use App\Livewire\Pulse\KnockSheet;
use App\Livewire\Pulse\Lines\QuoteRequest as LinesQuoteRequest;
use App\Livewire\Pulse\Me\NotificationSettings;
use App\Livewire\Pulse\Me\Onboarding;
use App\Livewire\Pulse\Need\NeedForm;
use App\Models\PulseOffer;
use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Business\BusinessTestCase;

/**
 * The words in an error are the words on the page. Every form is submitted empty (or wrong) and the errors it shows
 * are read the way a person reads them: no column or property name (title_text, whenOrQty, work_history.0.org),
 * no "field" talk, and each refusal said once on the screen, at the control it belongs to.
 */
class ValidationVoiceTest extends BusinessTestCase
{
    use RefreshDatabase;

    private function assertVoice(Testable $component, string $label): void
    {
        $errors = $component->errors();
        $this->assertNotEmpty($errors->all(), "{$label}: submitting it empty must say what is missing");

        $html = $component->html();

        foreach ($errors->all() as $message) {
            $this->assertDoesNotMatchRegularExpression('/\b[a-z]{2,}_[a-z0-9_]+\b/', $message, "{$label}: snake_case in \"{$message}\"");
            $this->assertDoesNotMatchRegularExpression('/\b[a-z]+[A-Z][A-Za-z]*\b/', $message, "{$label}: camelCase in \"{$message}\"");
            $this->assertDoesNotMatchRegularExpression('/\bfield\b|\.\d+\.|\.\*/i', $message, "{$label}: form talk in \"{$message}\"");
            $this->assertLessThanOrEqual(1, substr_count($html, e($message)), "{$label}: \"{$message}\" is shown more than once");
        }
    }

    public function test_every_service_validator_speaks_without_field_names(): void
    {
        // Whatever rule a service trips, the default line must not name the attribute in code.
        $v = Validator::make(
            ['work_history' => 'x', 'whenOrQty' => str_repeat('a', 500), 'amount_cents' => 'abc', 'needed_by' => 'tomorrowish', 'newImages' => 5],
            ['work_history' => 'array', 'whenOrQty' => 'max:10', 'amount_cents' => 'integer', 'needed_by' => 'date', 'newImages' => 'array', 'hiring_signal' => 'required', 'work_history.*.from_year' => 'required']
        );
        $this->assertTrue($v->fails());

        foreach ($v->errors()->all() as $message) {
            $this->assertDoesNotMatchRegularExpression('/[a-z]_[a-z]|[a-z][A-Z]/', $message, $message);
        }
    }

    public function test_the_need_form_says_it_once_in_its_own_words(): void
    {
        $c = Livewire::actingAs($this->person())->test(NeedForm::class)->set('title', '')->set('reach', 'national')->call('save');

        $this->assertVoice($c, 'need form');
        $c->assertSee('Say what you need in a line.');
        $this->assertSame(1, substr_count($c->html(), 'Say what you need in a line.'));
        $this->assertStringNotContainsString('title field', $c->html());
    }

    public function test_the_other_listing_forms_speak_plainly(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        $this->assertVoice(Livewire::actingAs($owner)->test(OfferManager::class, ['entity' => $page])->call('newOffer')->set('title', '')->call('save'), 'offer form');
        $this->assertVoice(Livewire::actingAs($owner)->test(RoleManager::class, ['entity' => $page])->call('newRole')->set('title', '')->call('save'), 'role form');
        $this->assertVoice(Livewire::actingAs($owner)->test(EntityForm::class)->set('name', '')->call('save'), 'business page form');
        $this->assertVoice(Livewire::actingAs($owner)->test(TeamManager::class, ['entity' => $page])->set('email', '')->call('add'), 'team form');
    }

    public function test_the_conversation_forms_speak_plainly(): void
    {
        $person = $this->person();

        $this->assertVoice(Livewire::actingAs($person)->test(KnockSheet::class, ['target' => ['entity' => $this->page()->id], 'bare' => true])->call('openSheet')->set('what', '')->call('send'), 'knock sheet');
        $this->assertVoice(Livewire::actingAs($person)->test(Door::class)->set('purpose', '')->call('open'), 'door');
        $this->assertVoice(Livewire::actingAs($person)->test(NotificationSettings::class)->set('digestHour', 'late')->set('quietStart', '25:61')->call('save'), 'notification settings');
    }

    public function test_the_quote_request_form_speaks_plainly(): void
    {
        $person = $this->person();
        $page = $this->page();
        $offer = PulseOffer::factory()->create(['entity_id' => $page->id]);

        $c = Livewire::actingAs($person)->test(LinesQuoteRequest::class, ['offerId' => $offer->id])->set('card.what', '')->call('send');
        $this->assertVoice($c, 'quote request');
    }

    public function test_the_welcome_age_step_and_the_old_post_form_speak_plainly(): void
    {
        $fresh = User::factory()->withPersonalTeam()->create();
        PulseProfile::create(['user_id' => $fresh->id, 'role' => 'customer']);
        DB::table('pulse_profiles')->where('user_id', $fresh->id)->update(['onboarded_at' => null]);

        $this->assertVoice(Livewire::actingAs($fresh)->test(Onboarding::class)->set('adult', false)->call('next'), 'welcome age step');
        $this->assertVoice(Livewire::actingAs($this->person())->test(CreatePost::class)->set('body', '')->call('publish'), 'post form');
    }
}

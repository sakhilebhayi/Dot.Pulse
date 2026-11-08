<?php

namespace Tests\Feature\LinesUi;

use App\Livewire\Pulse\Lines\Inbox;
use App\Livewire\Pulse\Lines\Report;
use App\Livewire\Pulse\Lines\Thread;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\User;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

/**
 * An anonymous professional stays anonymous on every screen the employer can open: the inbox in every tab, the line
 * page, the report form, the page source and the component state that is sent to the browser.
 */
class AnonymousNonLeakTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: PulseEntity, 3: PulseConversation} */
    private function invite(): array
    {
        [$pro, $signal] = $this->talent('anonymous');
        $employer = $this->person([], ['name' => 'Zanele Mokoena']);
        $page = $this->page($employer, ['name' => 'Acme Works', 'is_employer' => true, 'contact_policy' => 'anyone']);
        $line = app(LineService::class)->knock($employer, ['signal' => $signal->id, 'as_entity' => $page->id], 'invite', ['what' => 'We have a bookkeeping role open', 'where' => 'Johannesburg']);

        return [$employer, $pro, $page, $line];
    }

    /** @return list<string> */
    private function secrets(User $pro): array
    {
        return array_values(array_filter(['Thandiwe', 'Zulu-Private', $pro->email, 'thandi.private', $pro->profile_photo_path, (string) $pro->profile_photo_url !== '' && ! str_contains((string) $pro->profile_photo_url, 'ui-avatars') ? $pro->profile_photo_url : null]));
    }

    private function assertNothingLeaks(string $html, User $pro, string $where): void
    {
        foreach ($this->secrets($pro) as $secret) {
            $this->assertStringNotContainsString($secret, $html, "{$where} leaks: {$secret}");
        }
        $this->assertStringNotContainsString('/u/'.$pro->id.'"', $html, "{$where} links to the professional's profile");
        $this->assertDoesNotMatchRegularExpression('/"(?:recipient_id|user_id)":/', $html, "{$where} carries an id in its state");
    }

    public function test_the_employers_inbox_shows_an_alias_in_every_tab_and_every_state(): void
    {
        [$employer, $pro, , $line] = $this->invite();
        $lines = app(LineService::class);

        // Waiting for the professional.
        foreach (['sent', 'requests', 'open', 'closed', 'ignored'] as $tab) {
            $html = Livewire::actingAs($employer)->test(Inbox::class, ['startTab' => $tab])->html();
            $this->assertNothingLeaks($html, $pro, "inbox {$tab} (waiting)");
        }
        $this->assertStringContainsString('Professional ', Livewire::actingAs($employer)->test(Inbox::class, ['startTab' => 'sent'])->html());

        // Accepted, with messages both ways.
        $lines->accept($pro, $line);
        $lines->send($pro, $line->fresh(), 'Happy to talk.');
        foreach (['sent', 'open', 'closed'] as $tab) {
            $this->assertNothingLeaks(Livewire::actingAs($employer)->test(Inbox::class, ['startTab' => $tab])->html(), $pro, "inbox {$tab} (open)");
        }
        $this->assertStringContainsString('Happy to talk.', Livewire::actingAs($employer)->test(Inbox::class, ['startTab' => 'open'])->html());

        // Closed.
        $lines->close($employer, $line->fresh());
        $this->assertNothingLeaks(Livewire::actingAs($employer)->test(Inbox::class, ['startTab' => 'closed'])->html(), $pro, 'inbox closed (closed)');
    }

    public function test_the_employers_inbox_after_a_quiet_decline_and_after_it_lapses(): void
    {
        [$employer, $pro, , $line] = $this->invite();
        app(LineService::class)->decline($pro, $line, 'not_a_fit');

        $html = Livewire::actingAs($employer)->test(Inbox::class, ['startTab' => 'closed'])->assertSee('Not a fit')->html();
        $this->assertNothingLeaks($html, $pro, 'inbox after decline');
        $this->assertStringNotContainsString('declined you', $html);
    }

    public function test_the_employers_line_page_in_each_state_never_names_the_professional(): void
    {
        [$employer, $pro, , $line] = $this->invite();
        $lines = app(LineService::class);

        $this->assertNothingLeaks(Livewire::actingAs($employer)->test(Thread::class, ['conversation' => $line])->html(), $pro, 'thread waiting');

        $lines->accept($pro, $line);
        $lines->send($pro, $line->fresh(), 'Happy to talk.');
        app(OutcomeService::class)->propose($pro, $line->fresh(), 'hired');

        $component = Livewire::actingAs($employer)->test(Thread::class, ['conversation' => $line->fresh()]);
        $this->assertNothingLeaks($component->html(), $pro, 'thread open');
        $component->call('askBlock', $line->id)->assertSee('Anonymous', false);
        $this->assertNothingLeaks($component->html(), $pro, 'thread block prompt');

        $lines->close($pro, $line->fresh());
        $this->assertNothingLeaks(Livewire::actingAs($employer)->test(Thread::class, ['conversation' => $line->fresh()])->html(), $pro, 'thread closed');
    }

    public function test_the_page_itself_and_its_report_form_do_not_leak(): void
    {
        [$employer, $pro, , $line] = $this->invite();
        app(LineService::class)->accept($pro, $line);

        $page = $this->actingAs($employer)->get("/lines/{$line->id}")->assertOk();
        $this->assertNothingLeaks($page->getContent(), $pro, 'line page source');

        $report = Livewire::actingAs($employer)->test(Report::class, ['subjectType' => 'conversation', 'subjectId' => $line->id, 'startOpen' => true]);
        $this->assertNothingLeaks($report->html(), $pro, 'report form');
    }

    public function test_once_they_reveal_the_employer_sees_the_name_and_not_before(): void
    {
        [$employer, $pro, , $line] = $this->invite();
        app(LineService::class)->accept($pro, $line);

        Livewire::actingAs($employer)->test(Thread::class, ['conversation' => $line->fresh()])->assertDontSee('Thandiwe');
        app(LineService::class)->reveal($pro, $line->fresh());
        Livewire::actingAs($employer)->test(Thread::class, ['conversation' => $line->fresh()])->assertSee('Thandiwe Zulu-Private')->assertSee('They showed who they are.');
    }

    public function test_the_professionals_own_view_shows_the_employer_and_the_hide_my_name_control(): void
    {
        [, $pro, , $line] = $this->invite();

        Livewire::actingAs($pro)->test(Inbox::class)->assertSee('Acme Works')->assertSee('We have a bookkeeping role open')
            ->assertSee('Accept')->assertSee('Not now');
    }
}

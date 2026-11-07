<?php

namespace Tests\Feature\Lines;

use App\Models\PulseEntityMember;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Lines\ConsentLedger;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\NotificationPrefs;
use App\Services\Pulse\Lines\QuoteService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class ConsentLedgerTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function ledger(): ConsentLedger
    {
        return app(ConsentLedger::class);
    }

    private function sentences(User $u): array
    {
        return array_column($this->ledger()->entries($u), 'sentence', 'key');
    }

    public function test_rows_are_plain_sentences_built_from_real_settings_each_with_a_withdraw_action(): void
    {
        [$pro] = $this->talent('anonymous');
        $page = $this->page($pro, ['name' => 'Karoo Logistics']);

        $rows = $this->ledger()->entries($pro);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNotSame('', $row['sentence']);
            $this->assertNotEmpty($row['withdraw']['action']);
            $this->assertNotEmpty($row['withdraw']['label']);
            $this->assertStringStartsWith($row['withdraw']['action'], $row['key']);
        }

        $s = $this->sentences($pro);
        $this->assertStringContainsString('cannot see your name until you reveal it', $s['discoverability.off']);
        $this->assertStringContainsString('People can contact you only about something of yours', $s['contact_policy.nobody']);
        $this->assertStringContainsString('07:30', $s['digest.off']);
        $this->assertStringContainsString('You answer for Karoo Logistics as owner', $s['entity.leave:'.$page->id]);
    }

    public function test_rows_follow_the_settings_as_they_change(): void
    {
        $u = $this->person(['discoverability' => 'off', 'contact_policy' => 'anyone']);
        $s = $this->sentences($u);
        $this->assertArrayNotHasKey('discoverability.off', $s, 'nothing to withdraw when nobody can find you');
        $this->assertStringContainsString('Anyone on Pulse can message you directly', $s['contact_policy.requests']);

        DB::table('pulse_profiles')->where('user_id', $u->id)->update(['discoverability' => 'open', 'contact_policy' => 'nobody']);
        $s = $this->sentences($u->fresh());
        $this->assertStringContainsString('find you by name', $s['discoverability.off']);
        $this->assertArrayNotHasKey('contact_policy.nobody', $s);

        app(NotificationPrefs::class)->update($u, ['digest' => false, 'frequency' => 'off']);
        $s = $this->sentences($u->fresh());
        $this->assertArrayNotHasKey('digest.off', $s);
        $this->assertArrayNotHasKey('email.off', $s);
    }

    public function test_an_open_line_reads_as_who_may_message_you_about_what(): void
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests', 'name' => 'Moyo Plumbing']);
        $offer = $this->offer($page, ['title' => 'Geyser repair']);
        $line = $this->openLine($customer, $page, 'enquiry', $offer);

        $pro = $this->policy($this->person(), 'requests');
        $toPro = $this->openLine($this->person([], ['name' => 'Sipho']), $pro, 'intro');
        $other = $toPro->initiator;

        $this->assertStringContainsString('Sipho', collect($this->sentences($pro))->first(fn ($s) => str_contains($s, 'may message you')));
        $this->assertStringContainsString('about', collect($this->sentences($pro))->first(fn ($s) => str_contains($s, 'may message you')));
    }

    public function test_a_quote_reads_as_the_business_may_message_you_until_the_slip_expires(): void
    {
        $user = $this->person();
        $need = $this->need($user);
        $page = $this->page(null, ['name' => 'Moyo Plumbing', 'contact_policy' => 'context']);
        $this->offer($page);
        [$line] = app(QuoteService::class)->request($user, ['need' => $need->id], [$page->id], $this->card());
        app(QuoteService::class)->respond($this->responderOf($page), $line, [
            'amount_cents' => 100000, 'earliest_date' => now()->addDay()->toDateString(), 'included' => 'Labour',
            'valid_until' => '2026-11-30',
        ]);

        $sentence = collect($this->sentences($user))->first(fn ($s) => str_contains($s, 'your quote'));

        $this->assertSame('Moyo Plumbing may message you about your quote until 30 Nov.', $sentence);
    }

    public function test_withdrawing_changes_the_real_setting(): void
    {
        [$pro, $signal] = $this->talent('anonymous');
        $ledger = $this->ledger();

        $ledger->withdraw($pro, 'discoverability.off');
        $this->assertSame('off', DB::table('pulse_profiles')->where('user_id', $pro->id)->value('discoverability'));
        $this->assertSame('paused', PulseSignal::find($signal->id)->status, 'the talent card stops being findable');

        $ledger->withdraw($pro, 'contact_policy.nobody');
        $this->assertSame('nobody', DB::table('pulse_profiles')->where('user_id', $pro->id)->value('contact_policy'));

        $ledger->withdraw($pro, 'digest.off');
        $this->assertFalse(app(NotificationPrefs::class)->get($pro)->digest);

        $ledger->withdraw($pro, 'email.off');
        $this->assertFalse(app(NotificationPrefs::class)->allowed($pro, 'lines', 'email'));

        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'consent.withdraw', 'actor_id' => $pro->id]);
    }

    public function test_withdrawing_a_line_closes_it_and_only_for_the_person_it_concerns(): void
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $line = $this->openLine($customer, $page);

        $this->expectException(AuthorizationException::class);
        $this->ledger()->withdraw($this->person(), 'line.close:'.$line->id);
    }

    public function test_leaving_a_page_is_blocked_for_the_only_owner_and_allowed_otherwise(): void
    {
        $page = $this->page();
        $owner = $this->responderOf($page);
        $second = $this->member($page, null, 'admin');

        try {
            $this->ledger()->withdraw($owner, 'entity.leave:'.$page->id);
            $this->fail('last owner');
        } catch (LineException $e) {
            $this->assertSame('not_allowed', $e->reason);
        }

        $this->ledger()->withdraw($second, 'entity.leave:'.$page->id);
        $this->assertFalse(PulseEntityMember::where('user_id', $second->id)->exists());
    }

    public function test_unknown_actions_are_refused(): void
    {
        $this->expectException(LineException::class);
        $this->ledger()->withdraw($this->person(), 'delete.everything:1');
    }
}

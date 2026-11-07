<?php

namespace Tests\Feature\Lines;

use App\Services\Pulse\Lines\LineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class PulseCheckEndpointTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/pulse/pulse-check')->assertUnauthorized();
    }

    public function test_it_returns_the_three_counts(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $lines = app(LineService::class);

        $a = $this->person();
        $b = $this->person();
        $lines->knock($a, ['entity' => $page->id], 'enquiry', $this->card());                    // a request waiting
        $open = $this->openLine($b, $page);                                                       // an open line
        $lines->send($b, $open, 'Are you there?');                                                // unread for the owner

        $this->actingAs($owner)->getJson('/api/pulse/pulse-check')
            ->assertOk()
            ->assertExactJson([
                'lines_unread' => 1,
                'requests' => 1,
                'notifications' => $owner->unreadNotifications()->count(),
            ])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertGreaterThan(0, $owner->unreadNotifications()->count());

        $lines->markRead($owner, $open);
        $this->actingAs($owner)->getJson('/api/pulse/pulse-check')->assertJsonPath('lines_unread', 0);

        $this->actingAs($b)->getJson('/api/pulse/pulse-check')->assertJsonPath('requests', 0);
        $this->actingAs($this->person())->getJson('/api/pulse/pulse-check')->assertExactJson(['lines_unread' => 0, 'requests' => 0, 'notifications' => 0]);
    }

    public function test_ignored_requests_stop_counting_and_the_senders_view_counts_replies(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $sender = $this->person();
        $lines = app(LineService::class);
        $c = $lines->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());

        $lines->ignoreQuietly($owner, $c);
        $this->actingAs($owner)->getJson('/api/pulse/pulse-check')->assertJsonPath('requests', 0);

        $other = $this->openLine($sender, $page);
        $lines->send($owner, $other, 'Hi, how can we help?');
        $this->actingAs($sender)->getJson('/api/pulse/pulse-check')->assertJsonPath('lines_unread', 1);
    }

    public function test_it_is_cheap_three_queries_and_no_more(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        foreach (range(1, 5) as $i) {
            $this->openLine($this->person(), $page);
        }

        $this->actingAs($owner);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/pulse/pulse-check')->assertOk();

        $pulse = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/from "(pulse_conversations|notifications)"/', $q['query']));
        $this->assertCount(3, $pulse, $pulse->pluck('query')->implode("\n"));
        $this->assertTrue($pulse->every(fn ($q) => str_contains($q['query'], 'count(*)')), 'counts only, no rows hydrated');
    }

    public function test_the_unread_queries_use_indexes_on_the_new_columns(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Reads sqlite_master.');
        }

        $indexes = collect(DB::select("select name from sqlite_master where type = 'index' and tbl_name = 'pulse_conversations'"))->pluck('name')->implode(' ');

        foreach (['recipient_id_status', 'initiator_id_status', 'entity_id_status', 'initiator_entity_id_status', 'quote_group'] as $needle) {
            $this->assertStringContainsString($needle, $indexes);
        }
    }
}

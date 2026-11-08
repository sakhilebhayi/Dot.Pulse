<?php

namespace Tests\Feature\Lines;

use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\ReportService;
use App\Services\Pulse\Lines\ResponseStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class ResponseStatsTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    public function test_nothing_received_means_no_rate_not_a_made_up_one(): void
    {
        $stats = ResponseStats::for($this->page());

        $this->assertSame(0, $stats['received']);
        $this->assertNull($stats['response_rate']);
        $this->assertNull($stats['median_first_response_seconds']);
    }

    public function test_rate_and_median_come_from_real_rows(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $lines = app(LineService::class);

        $answered = [];
        foreach ([60, 600, 3600] as $seconds) {
            $c = $lines->knock($this->person(), ['entity' => $page->id], 'enquiry', $this->card());
            $this->travel($seconds)->seconds();
            $lines->accept($owner, $c);
            $answered[] = $c;
        }
        $declined = $lines->knock($this->person(), ['entity' => $page->id], 'enquiry', $this->card());
        $lines->decline($owner, $declined);
        $ignored = $lines->knock($this->person(), ['entity' => $page->id], 'enquiry', $this->card());
        $lines->ignoreQuietly($owner, $ignored);

        $this->travel(3)->days(); // the ignored one is no longer fresh

        $stats = ResponseStats::for($page);

        $this->assertSame(5, $stats['received']);
        $this->assertSame(4, $stats['answered'], 'accepted and declined are answers');
        $this->assertSame(0.8, $stats['response_rate']);
        // answered in 0 (the decline), 60, 600 and 3600 seconds: the median of four is the mean of the middle two
        $this->assertSame(330, $stats['median_first_response_seconds']);
    }

    public function test_fresh_unanswered_requests_are_not_held_against_the_page(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        app(LineService::class)->knock($this->person(), ['entity' => $page->id], 'enquiry', $this->card());

        $this->assertSame(0, ResponseStats::for($page)['received']);

        $this->travel(49)->hours();
        $stats = ResponseStats::for($page);
        $this->assertSame(1, $stats['received']);
        $this->assertSame(0.0, $stats['response_rate']);
    }

    public function test_spam_is_left_out_and_people_get_stats_for_direct_lines_only(): void
    {
        $person = $this->policy($this->person(), 'requests');
        $lines = app(LineService::class);
        $spam = $lines->knock($this->person(), ['user' => $person->id], 'intro', $this->card());
        app(ReportService::class)->report($person, $spam, 'spam', null);
        $real = $lines->knock($this->person(), ['user' => $person->id], 'intro', $this->card());
        $lines->accept($person, $real);

        $stats = ResponseStats::for($person);

        $this->assertSame(1, $stats['received']);
        $this->assertSame(1.0, $stats['response_rate']);
    }

    public function test_an_old_request_that_expired_counts_as_unanswered(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        app(LineService::class)->knock($this->person(), ['entity' => $page->id], 'enquiry', $this->card());
        $this->travel(15)->days();
        $this->artisan('pulse:lines:sweep');

        $stats = ResponseStats::for($page);
        $this->assertSame(1, $stats['received']);
        $this->assertSame(0, $stats['answered']);
    }
}

<?php

namespace Tests\Feature\Pulse;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

/**
 * Every link in the rail, the phone bar and the compose drawer goes somewhere real: no '#', no 404, no 500.
 */
class ShellLinksTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    /** @return list<string> the hrefs inside the rail, the phone bar and the compose drawer */
    private function shellHrefs(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);

        $hrefs = [];
        foreach ($xpath->query('//nav[contains(@class,"sh-rail")]//a | //nav[contains(@class,"sh-bar")]//a | //*[@id="compose"]//a') as $a) {
            $hrefs[] = $a->getAttribute('href');
        }

        return array_values(array_unique($hrefs));
    }

    public function test_the_rail_the_phone_bar_and_the_compose_drawer_all_resolve(): void
    {
        $user = $this->pulseUser();
        $html = $this->actingAs($user)->get('/floor')->assertOk()->getContent();
        $hrefs = $this->shellHrefs($html);

        $this->assertNotEmpty($hrefs);

        foreach ($hrefs as $href) {
            $this->assertNotSame('#', $href, 'a link that goes nowhere');
            $this->assertStringNotContainsString('href="#"', $href);

            $path = parse_url($href, PHP_URL_PATH).(($q = parse_url($href, PHP_URL_QUERY)) ? '?'.$q : '');
            $response = $this->actingAs($user)->get($path);
            if ($response->isRedirect()) {
                $response = $this->actingAs($user)->get(Str::after($response->headers->get('Location'), url('/')));
            }
            $this->assertTrue($response->isSuccessful(), "{$href} answered {$response->getStatusCode()}");
        }
    }

    public function test_the_compose_drawer_offers_a_need_a_page_a_door_and_a_post(): void
    {
        $html = $this->actingAs($this->pulseUser())->get('/floor')->assertOk()->getContent();

        foreach ([route('needs.create'), route('entities.create'), route('door'), route('community.feed').'?compose=1#share'] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $html, $href);
        }
        $this->assertStringNotContainsString('href="'.route('dashboard').'"', $html, 'the old dashboard redirect is not a link in the shell');
    }

    public function test_the_composer_the_drawer_points_at_is_on_that_page(): void
    {
        $user = $this->pulseUser();

        $this->actingAs($user)->get(route('community.feed'))->assertOk()->assertSee('id="share"', false)->assertDontSee('id="compose-form"', false);
        $this->actingAs($user)->get(route('community.feed').'?compose=1')->assertOk()->assertSee('id="compose-form"', false);
    }
}

<?php

namespace Tests\Feature\Polish;

use App\Models\PulseEntityMember;
use Tests\Feature\Floor\FloorTestCase;

class ShellNavTest extends FloorTestCase
{
    private function railActive(string $html): array
    {
        preg_match('~<nav class="sh-rail".*?</nav>~s', $html, $rail);
        preg_match_all('~<a class="sh-verb"[^>]*aria-current="page"[^>]*>.*?<span[^>]*>(.*?)</span>~s', $rail[0] ?? '', $m);

        return array_map('trim', $m[1]);
    }

    public function test_a_person_with_no_business_sees_no_businesses_entry(): void
    {
        $html = $this->actingAs($this->me)->get('/floor')->assertOk()->getContent();

        $this->assertStringNotContainsString('Your business', $html);
    }

    public function test_an_owner_sees_manage_links_in_the_rail_and_the_account_menu(): void
    {
        $owner = $this->person();
        $entity = $this->dEntity($owner, ['name' => 'Kettle and Plum', 'is_seller' => true]);

        $html = $this->actingAs($owner)->get('/floor')->assertOk()->getContent();

        $this->assertStringContainsString('Your business', $html);
        $this->assertStringContainsString('href="'.route('entities.manage', $entity).'"', $html);
        $this->assertStringContainsString('Manage Kettle and Plum', $html);
    }

    public function test_several_pages_are_listed_and_a_non_member_sees_none_of_them(): void
    {
        $owner = $this->person();
        $this->dEntity($owner, ['name' => 'First page']);
        $this->dEntity($owner, ['name' => 'Second page']);

        $html = $this->actingAs($owner)->get('/floor')->getContent();
        $this->assertStringContainsString('Your businesses', $html);
        $this->assertStringContainsString('Manage First page', $html);
        $this->assertStringContainsString('Manage Second page', $html);

        $this->assertStringNotContainsString('First page', $this->actingAs($this->me)->get('/floor')->getContent());
    }

    public function test_exactly_one_item_is_active_in_the_rail(): void
    {
        $owner = $this->person();
        $entity = $this->dEntity($owner, ['name' => 'Kettle and Plum', 'is_seller' => true]);
        $other = $this->person();

        foreach (['/floor' => 'Floor', '/saved' => 'Saved', '/search' => 'Search', '/door' => 'Open door', '/me/profile' => 'You', '/lines' => 'Lines'] as $path => $label) {
            $this->assertSame([$label], $this->railActive($this->actingAs($owner)->get($path)->getContent()), $path);
        }

        $this->assertSame(['Kettle and Plum'], $this->railActive($this->actingAs($owner)->get(route('entities.manage', $entity))->getContent()));
        $this->assertSame(['Kettle and Plum'], $this->railActive($this->actingAs($owner)->get(route('entities.manage.offers', $entity))->getContent()));
        $this->assertNotNull($other);
    }
}

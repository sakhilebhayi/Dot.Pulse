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

    public function test_business_need_and_community_pages_light_the_right_item_and_the_phone_bar_none_on_manage(): void
    {
        $owner = $this->person();
        $shop = $this->dEntity($owner, ['name' => 'Kettle and Plum', 'is_seller' => true]);
        $hirer = $this->dEntity($this->person(), ['name' => 'Hiring House', 'is_seller' => false, 'is_employer' => true]);
        $need = \App\Models\PulseNeed::factory()->create(['user_id' => $this->me->id]);

        // A business page the person does not run: Trade for a seller, Work for an employer. Their own: the business.
        $this->assertSame(['Trade'], $this->railActive($this->actingAs($this->me)->get(route('entities.show', $shop))->getContent()));
        $this->assertSame(['Work'], $this->railActive($this->actingAs($this->me)->get(route('entities.show', $hirer))->getContent()));
        $this->assertSame(['Kettle and Plum'], $this->railActive($this->actingAs($owner)->get(route('entities.show', $shop))->getContent()));

        // Their own need is theirs ("You"); the community has its own entry, and Floor stays on the floor routes.
        $this->assertSame(['You'], $this->railActive($this->actingAs($this->me)->get(route('needs.show', $need))->getContent()));
        $this->assertSame(['Community'], $this->railActive($this->actingAs($this->me)->get(route('community.feed'))->getContent()));

        // The phone bar has no business entry: "You" must not light up on manage pages.
        $bar = $this->actingAs($owner)->get(route('entities.manage', $shop))->getContent();
        preg_match('~<nav class="sh-bar".*?</nav>~s', $bar, $nav);
        $this->assertStringNotContainsString('aria-current', $nav[0]);
        $this->assertStringContainsString('aria-current="page"', $this->actingAs($owner)->get('/me/profile')->getContent());
    }

    public function test_the_stock_jetstream_profile_sends_people_to_their_floor_profile(): void
    {
        $this->actingAs($this->me)->get('/user/profile')->assertRedirect(route('me.profile'));
        $this->actingAs($this->me)->get('/me/account')->assertOk()->assertSee('Account and security')->assertSee('Go to Privacy');
        $this->assertSame(1, substr_count($this->actingAs($this->me)->get('/me/profile')->getContent(), 'Headline'), 'one profile editor, not two');
    }

    public function test_the_welcome_flow_is_a_single_task_page_with_no_rail_bar_or_compose(): void
    {
        $fresh = \App\Models\User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($fresh)->get('/welcome-in')->assertOk()->getContent();

        $this->assertStringContainsString('sh-body--focus', $html);
        $this->assertStringNotContainsString('class="sh-rail"', $html);
        $this->assertStringNotContainsString('class="sh-bar"', $html);
        $this->assertStringNotContainsString('sh-compose-top', $html);
    }
}

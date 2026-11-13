<?php

namespace Tests\Feature\Floor;

use App\Livewire\Pulse\Floor\Support\Arrival;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The top of the Floor, where a person lands after signing in through InfoDot: the original logo, a welcome from their
 * own records, exactly one dominant action that leads somewhere real for their role, the way back to InfoDot, and an
 * honest first visit that shows no number it did not count.
 */
class ArrivalTest extends FloorTestCase
{
    /** The logo as it was first committed. If this changes, the artwork changed. */
    private const LOGO_SHA256 = '0c52ae250d5a5f384e22a589ffddf1abb6f7174a78ccea4c18b4dd90fd6fd6e4';

    private function floorAs(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('floor'))->assertOk();
    }

    /** @return list<string> the href of every element that carries the primary-action marker */
    private function primaryActions(string $html): array
    {
        preg_match_all('/<(?:a|button)\b[^>]*\bdata-primary-action\b[^>]*>/', $html, $tags);

        return array_map(fn (string $tag) => preg_match('/\bhref="([^"]*)"/', $tag, $m) ? html_entity_decode($m[1]) : '', $tags[0]);
    }

    /** The first-visit block alone, so a number elsewhere on the page (a time, a badge) cannot hide in the assertion. */
    private function emptyBlock(string $html): string
    {
        $this->assertSame(1, preg_match('/<div[^>]*data-floor-empty[^>]*>(.*?)<\/dl>/s', $html, $m), 'the first-visit block is on the page');

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    public function test_each_arriving_role_gets_exactly_one_primary_action_and_its_target_answers(): void
    {
        $person = $this->me;

        $seller = $this->person();
        $shop = $this->dEntity($seller, ['name' => 'Selling Shop', 'is_seller' => true, 'is_employer' => false]);

        $employer = $this->person();
        $house = $this->dEntity($employer, ['name' => 'Hiring House', 'is_seller' => false, 'is_employer' => true]);

        $moderator = $this->person();
        DB::table('pulse_profiles')->where('user_id', $moderator->id)->update(['role' => 'moderator']);

        $expected = [
            [$person, 'Say what you need', route('needs.create')],
            [$seller, 'Add an offer', route('entities.manage.offers', ['entity' => $shop->slug])],
            [$employer, 'Post a role', route('entities.manage.roles', ['entity' => $house->slug])],
            [$moderator, 'Say what you need', route('needs.create')],
        ];

        foreach ($expected as [$user, $label, $href]) {
            $response = $this->floorAs($user);
            $actions = $this->primaryActions($response->getContent());

            $this->assertSame([$href], $actions, 'one primary action, leading to '.$label);
            $response->assertSee($label);
            $this->actingAs($user)->get($href)->assertOk();
        }
    }

    public function test_the_arrival_shows_the_original_logo_file_unaltered(): void
    {
        $path = public_path(Arrival::LOGO);

        $this->assertFileExists($path);
        $this->assertSame(self::LOGO_SHA256, hash_file('sha256', $path));

        $this->floorAs($this->me)
            ->assertSee('src="'.asset(Arrival::LOGO).'"', false)
            ->assertSee('alt="Dot.Pulse"', false);
    }

    public function test_another_platforms_logo_is_not_shipped_with_this_one(): void
    {
        $this->assertFileDoesNotExist(public_path('dot_projects.png'));
    }

    public function test_the_welcome_uses_the_first_name_and_the_infodot_company(): void
    {
        $user = $this->person([], ['name' => 'Naledi Fenwick']);
        $company = Team::forceCreate(['user_id' => $user->id, 'name' => 'Fenwick Holdings', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $company->id])->save();

        $this->floorAs($user->fresh())
            ->assertSeeInOrder(['Welcome, Naledi.', 'You are here for', 'Fenwick Holdings'])
            ->assertSee('Introductions that say why.');
    }

    public function test_a_personal_team_is_never_shown_as_a_company_and_a_business_page_stands_in(): void
    {
        $alone = $this->person([], ['name' => 'Zinhle Dawnfield']);
        $response = $this->floorAs($alone)->assertSee('Welcome, Zinhle.')->assertDontSee('You are here for');
        $this->assertSame(1, preg_match('/<section[^>]*data-floor-arrival[^>]*>(.*?)<\/section>/s', $response->getContent(), $arrival));
        $this->assertStringNotContainsString('Team', strip_tags($arrival[1]));

        $owner = $this->person([], ['name' => 'Bongani Quillfeather']);
        $this->dEntity($owner, ['name' => 'Quillfeather Signs']);
        $this->floorAs($owner)->assertSeeInOrder(['Welcome, Bongani.', 'You are here for', 'Quillfeather Signs']);
    }

    public function test_the_way_back_to_infodot_follows_the_configured_host(): void
    {
        config(['ecosystem.infodot.redeem_url' => 'https://infodot.co.za/api/ecosystem/redeem']);

        $this->floorAs($this->me)
            ->assertSee('<a href="https://infodot.co.za/home">InfoDot</a>', false)
            ->assertSee('<a href="https://infodot.co.za/platforms">Platforms</a>', false);
    }

    public function test_an_empty_first_visit_says_what_the_matcher_holds_and_shows_no_number(): void
    {
        $response = $this->floorAs($this->me);
        $block = $this->emptyBlock($response->getContent());

        $response->assertSee('Nothing fits yet.');
        foreach (['No skills on your profile yet.', 'Nothing said yet.', 'No open need.', 'No live offer.', 'No business followed yet.', $this->geo->jhb->name] as $line) {
            $this->assertStringContainsString($line, $block);
        }
        $this->assertDoesNotMatchRegularExpression('/\d/', $block, 'nothing was counted, so no number is shown');

        // Nothing implies that anyone else is here.
        $response->assertDontSee("That's the round", false)->assertDontSee('data-beat="', false);
    }

    public function test_every_number_on_an_empty_first_visit_is_a_count_of_the_persons_own_records(): void
    {
        $user = $this->person(['skills' => ['plumbing'], 'open_to' => ['suppliers'], 'place' => $this->geo->jhb]);
        $this->dNeed($user, ['title' => 'A van for two days']);
        $this->dNeed($user, ['title' => 'Signwriting for the shop front']);
        $followed = $this->dEntity($this->person(), ['name' => 'Followed Works']);
        $this->dFollowEntity($user, $followed);

        $block = $this->emptyBlock($this->floorAs($user)->getContent());

        $this->assertStringContainsString('Plumbing', $block);
        $this->assertStringContainsString('Suppliers and trade partners', $block);
        $this->assertStringContainsString('2 open needs', $block);
        $this->assertStringContainsString('1 business', $block);

        preg_match_all('/\d+/', $block, $numbers);
        $this->assertSame(['2', '1'], $numbers[0], 'two needs and one followed business were seeded; no other number appears');
    }

    public function test_the_first_action_is_not_offered_twice_on_an_empty_first_visit(): void
    {
        $html = $this->floorAs($this->me)->getContent();

        $this->assertSame(1, preg_match('/<div[^>]*data-floor-empty[^>]*>(.*?)<\/dl>/s', $html, $block));
        $this->assertStringContainsString('What you need', $block[1]);
        $this->assertStringNotContainsString(route('needs.create'), $block[1], 'the first action is already the button above the block');
        $this->assertStringContainsString(route('search'), $block[1]);
    }

    public function test_the_round_stays_directly_under_the_arrival(): void
    {
        $this->offerFor('Bookkeeping for small shops', [['code' => 'need_offer', 'text' => 'You need bookkeeping, they offer bookkeeping', 'weight' => 40]]);

        $this->floorAs($this->me)->assertSeeInOrder([
            'data-floor-arrival',
            'data-primary-action',
            'data-floor-round',
            'Bookkeeping for small shops',
            'You need bookkeeping, they offer bookkeeping',
        ], false);
    }

    public function test_the_mode_pages_keep_their_plain_heading(): void
    {
        $this->actingAs($this->me)->get(route('trade'))
            ->assertOk()
            ->assertDontSee('data-floor-arrival', false)
            ->assertDontSee('data-primary-action', false)
            ->assertSee('Things to buy, sell and swap.');
    }

    public function test_a_new_member_arrives_in_the_welcome_flow_with_the_logo_a_welcome_and_one_primary_action(): void
    {
        $new = User::factory()->withPersonalTeam()->create(['name' => 'Thabo Larkspur']);

        $this->actingAs($new)->get(route('floor'))->assertRedirect(route('onboarding'));

        $response = $this->actingAs($new)->get(route('onboarding'))->assertOk()
            ->assertSee('Welcome, Thabo.')
            ->assertSee('src="'.asset(Arrival::LOGO).'"', false)
            ->assertSee('https://infodot.co.za/platforms', false);

        $this->assertSame(1, preg_match_all('/<(?:a|button)\b[^>]*\bdata-primary-action\b/', $response->getContent()));
    }
}

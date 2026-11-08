<?php

namespace Tests\Feature\Discovery;

use App\Models\Community;
use App\Models\PulseMatch;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\ContextLoader;
use App\Services\Pulse\Discovery\Matcher;
use App\Services\Pulse\Discovery\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class MatcherTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private object $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geo = $this->dGeo();
    }

    private function matcher(): Matcher
    {
        return app(Matcher::class);
    }

    /** @return array<string, array<string, mixed>> reason code => reason, for the one match of $u on $signal */
    private function reasonsFor(User $u, PulseSignal $s): array
    {
        $this->matcher()->forUser($u);
        $m = PulseMatch::where('user_id', $u->id)->where('signal_id', $s->id)->first();

        return $m === null ? [] : collect($m->reasons)->keyBy('code')->all();
    }

    public function test_a_need_meets_an_offer_with_a_plain_sentence(): void
    {
        $buyer = $this->dUser(['place' => $this->geo->jhb]);
        $this->dNeed($buyer, ['title' => 'Need a bookkeeper', 'tags' => ['Book Keeping'], 'place_id' => $this->geo->jhb->id]);
        $seller = $this->dUser();
        $signal = $this->dOfferSignal($this->dEntity($seller), ['bookkeeping'], ['place' => $this->geo->jhb]);

        $reasons = $this->reasonsFor($buyer, $signal);

        $this->assertArrayHasKey('need_offer', $reasons);
        $this->assertSame('You need bookkeeping, they offer bookkeeping.', $reasons['need_offer']['text']);
        $this->assertArrayHasKey('place_city', $reasons);
        $this->assertSame('bookkeeping', $reasons['need_offer']['ref']);
    }

    public function test_a_professional_meets_an_employer_with_a_hiring_signal_before_any_role_exists(): void
    {
        $pro = $this->dUser(['place' => $this->geo->jhb, 'skills' => ['bookkeeping']]);
        $employer = $this->dEntity($this->dUser(), ['name' => 'Wildfig', 'is_employer' => true, 'hiring_signal' => 'building']);
        $signal = $this->dSignal([
            'kind' => 'business', 'subject_type' => 'entity', 'subject_id' => $employer->id, 'entity_id' => $employer->id,
            'owner_id' => $employer->created_by, 'title' => 'Wildfig', 'skills' => ['bookkeeping', 'payroll'], 'place' => $this->geo->jhb,
        ]);

        $reasons = $this->reasonsFor($pro, $signal);

        $this->assertArrayHasKey('skill_role', $reasons);
        $this->assertSame('They are building a team and want your skills: bookkeeping.', $reasons['skill_role']['text']);
        $this->assertSame('bookkeeping', $reasons['skill_role']['ref']);

        $other = $this->dUser(['place' => $this->geo->jhb, 'skills' => ['welding']]);
        $this->assertArrayNotHasKey('skill_role', $this->reasonsFor($other, $signal));
    }

    public function test_the_score_is_exactly_the_sum_of_the_reason_weights(): void
    {
        $buyer = $this->dUser(['place' => $this->geo->jhb]);
        $this->dNeed($buyer, ['tags' => ['bookkeeping']]);
        $signal = $this->dOfferSignal($this->dEntity($this->dUser()), ['bookkeeping'], ['place' => $this->geo->jhb]);

        $this->matcher()->forUser($buyer);
        $m = PulseMatch::firstWhere(['user_id' => $buyer->id, 'signal_id' => $signal->id]);

        $this->assertEqualsWithDelta(array_sum(array_column($m->reasons, 'weight')), $m->score, 0.5);
        $this->assertLessThanOrEqual(100, $m->score);
        $this->assertSame(array_column($m->reasons, 'code'), $this->matcher()->explain($m) ? array_column($this->matcher()->explain($m), 'code') : []);
    }

    public function test_an_offer_meets_a_need(): void
    {
        $seller = $this->dUser(['place' => $this->geo->jhb]);
        $entity = $this->dEntity($seller);
        $this->dOffer($entity, ['tags' => ['plumbing'], 'title' => 'Geyser repairs']);
        $asker = $this->dUser();
        $need = $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'owner_id' => $asker->id, 'topics' => ['plumber'], 'place' => $this->geo->jhb, 'reach' => 'region']);

        $reasons = $this->reasonsFor($seller, $need);

        $this->assertArrayHasKey('offer_need', $reasons);
        $this->assertSame('You offer plumbing, they need plumbing.', $reasons['offer_need']['text']);
    }

    public function test_a_matched_need_is_shown_only_to_people_whose_offers_match(): void
    {
        $asker = $this->dUser();
        $needRow = $this->dNeed($asker, ['visibility' => 'matched', 'tags' => ['plumbing']]);
        $signal = $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'subject_id' => $needRow->id, 'owner_id' => $asker->id, 'topics' => ['plumbing']]);

        // A plumber by skill alone is not enough for a matched need...
        $skilled = $this->dUser(['skills' => ['plumbing']]);
        $this->assertSame([], $this->reasonsFor($skilled, $signal));

        // ...but a business that offers plumbing sees it.
        $seller = $this->dUser();
        $this->dOffer($this->dEntity($seller), ['tags' => ['plumbing']]);
        $this->assertArrayHasKey('offer_need', $this->reasonsFor($seller, $signal));

        // An open need is also shown to people with the skill.
        $needRow->update(['visibility' => 'open']); // the observer re-indexes the need; restate what this test pins
        $signal = $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'subject_id' => $needRow->id, 'owner_id' => $asker->id, 'topics' => ['plumbing']]);
        $this->assertArrayHasKey('topic', $this->reasonsFor($skilled, $signal));
    }

    public function test_a_role_meets_the_skills_and_experience_of_a_professional(): void
    {
        $pro = $this->dUser(['skills' => ['Payroll', 'Bookkeeping', 'Tax'], 'experience_years' => 7, 'place' => $this->geo->pta]);
        $employer = $this->dUser();
        $entity = $this->dEntity($employer, ['is_employer' => true]);
        $role = $this->dRole($entity, ['seniority' => 'Mid-level', 'skills' => ['payroll', 'bookkeeping']]);
        $signal = $this->dSignal(['kind' => 'role', 'subject_type' => 'role', 'subject_id' => $role->id, 'entity_id' => $entity->id, 'owner_id' => $employer->id, 'skills' => ['payroll', 'bookkeeping'], 'place' => $this->geo->pta]);

        $reasons = $this->reasonsFor($pro, $signal);

        $this->assertArrayHasKey('skill_role', $reasons);
        $this->assertStringContainsString('payroll and bookkeeping', $reasons['skill_role']['text']);
        $this->assertStringContainsString('experience meets', $reasons['skill_role']['text']);
        $this->assertArrayHasKey('place_city', $reasons);
    }

    public function test_an_employer_meets_open_talent_without_a_name(): void
    {
        $employer = $this->dUser(['place' => $this->geo->jhb]);
        $entity = $this->dEntity($employer, ['is_employer' => true, 'place_id' => $this->geo->jhb->id]);
        $this->dRole($entity, ['skills' => ['welding', 'fabrication'], 'seniority' => 'senior']);

        $talent = $this->dUser(['skills' => ['welding'], 'experience_years' => 12, 'discoverability' => 'anonymous', 'place' => $this->geo->sandton]);
        $profileId = DB::table('pulse_profiles')->where('user_id', $talent->id)->value('id');
        $signal = $this->dSignal(['kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $talent->id, 'anonymous' => true, 'skills' => ['welding'], 'place' => $this->geo->sandton, 'reach' => 'region']);

        $reasons = $this->reasonsFor($employer, $signal);

        $this->assertArrayHasKey('role_talent', $reasons);
        $this->assertStringNotContainsString($talent->name, json_encode($reasons));
        $this->assertArrayHasKey('place_city', $reasons, 'a suburb counts as the same city as its metro');
        $this->assertArrayNotHasKey('follows', $reasons);
    }

    public function test_talent_is_hidden_from_everyone_who_is_not_an_employer_and_when_not_discoverable_or_not_adult(): void
    {
        $pro = $this->dUser(['skills' => ['welding'], 'discoverability' => 'anonymous', 'place' => $this->geo->jhb]);
        $profileId = DB::table('pulse_profiles')->where('user_id', $pro->id)->value('id');
        $signal = $this->dSignal(['kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $pro->id, 'anonymous' => true, 'skills' => ['welding'], 'place' => $this->geo->jhb]);

        $employer = $this->dUser(['place' => $this->geo->jhb]);
        $entity = $this->dEntity($employer, ['is_employer' => true]);
        $this->dRole($entity, ['skills' => ['welding']]);
        $this->assertArrayHasKey('role_talent', $this->reasonsFor($employer, $signal));

        // A seller with the same skill, not an employer: never sees talent.
        $seller = $this->dUser(['skills' => ['welding'], 'place' => $this->geo->jhb]);
        $this->dEntity($seller);
        $this->assertSame([], $this->reasonsFor($seller, $signal));

        // Switching discoverability off removes the match on the next recompute.
        DB::table('pulse_profiles')->where('id', $profileId)->update(['discoverability' => 'off']);
        $this->assertSame([], $this->reasonsFor($employer, $signal));

        // Age not confirmed: never discoverable.
        DB::table('pulse_profiles')->where('id', $profileId)->update(['discoverability' => 'open', 'age_confirmed_at' => null]);
        $this->assertSame([], $this->reasonsFor($employer, $signal));

        DB::table('pulse_profiles')->where('id', $profileId)->update(['age_confirmed_at' => now()]);
        $this->assertArrayHasKey('role_talent', $this->reasonsFor($employer, $signal));
    }

    public function test_talent_can_hide_from_their_current_employer(): void
    {
        $employer = $this->dUser(['place' => $this->geo->jhb]);
        $entity = $this->dEntity($employer, ['name' => 'Acme Steel', 'is_employer' => true]);
        $this->dRole($entity, ['skills' => ['welding']]);

        $pro = $this->dUser(['skills' => ['welding'], 'discoverability' => 'anonymous', 'place' => $this->geo->jhb,
            'work_history' => [['employer' => 'ACME Steel', 'current' => true, 'hide_from_employer' => true]]]);
        $profileId = DB::table('pulse_profiles')->where('user_id', $pro->id)->value('id');
        $signal = $this->dSignal(['kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $pro->id, 'anonymous' => true, 'skills' => ['welding'], 'place' => $this->geo->jhb]);

        $this->assertSame([], $this->reasonsFor($employer, $signal));
    }

    public function test_topic_overlap_with_interests_matches_news_and_events(): void
    {
        $u = $this->dUser(['interests' => ['Solar installation'], 'place' => $this->geo->jhb]);
        $news = $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id, 'topics' => ['solar-installation']]);
        $event = $this->dSignal(['kind' => 'event', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id, 'topics' => ['solar-installation']]);
        $off = $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id, 'topics' => ['welding']]);

        $this->matcher()->forUser($u);

        $this->assertStringContainsString('News about solar installation', PulseMatch::firstWhere('signal_id', $news->id)->reasons[0]['text']);
        $this->assertNotNull(PulseMatch::firstWhere('signal_id', $event->id));
        $this->assertNull(PulseMatch::firstWhere('signal_id', $off->id), 'no shared topic, no match');
    }

    public function test_an_ask_you_can_answer_uses_your_skills(): void
    {
        $expert = $this->dUser(['skills' => ['Tax']]);
        $ask = $this->dSignal(['kind' => 'ask', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id, 'topics' => ['tax-services']]);

        $reasons = $this->reasonsFor($expert, $ask);

        $this->assertSame('Someone is asking about tax services, which you know about.', $reasons['topic']['text']);
    }

    public function test_place_tiers_and_reach(): void
    {
        $u = $this->dUser(['place' => $this->geo->jhb, 'reach' => 'country', 'interests' => ['plumbing']]);
        $city = $this->dSignal(['topics' => ['plumbing'], 'place' => $this->geo->sandton, 'reach' => 'national']);
        $region = $this->dSignal(['topics' => ['plumbing'], 'place' => $this->geo->pta, 'reach' => 'national']);
        $country = $this->dSignal(['topics' => ['plumbing'], 'place' => $this->geo->cpt, 'reach' => 'national']);
        $abroad = $this->dSignal(['topics' => ['plumbing'], 'place' => $this->geo->lagos, 'reach' => 'national']);
        $localOnly = $this->dSignal(['topics' => ['plumbing'], 'place' => $this->geo->pta, 'reach' => 'local']);

        $this->matcher()->forUser($u);
        $get = fn ($s) => collect(PulseMatch::firstWhere('signal_id', $s->id)?->reasons ?? [])->pluck('code')->all();

        $this->assertContains('place_city', $get($city));
        $this->assertContains('place_region', $get($region));
        $this->assertContains('place_country', $get($country));
        $this->assertSame([], $get($abroad), 'a national listing in another country is out of reach');
        $this->assertSame([], $get($localOnly), 'a local-only listing in another city is out of reach');
    }

    public function test_the_person_reach_limits_what_they_see(): void
    {
        $homebody = $this->dUser(['place' => $this->geo->jhb, 'reach' => 'city', 'interests' => ['plumbing']]);
        $far = $this->dSignal(['topics' => ['plumbing'], 'place' => $this->geo->cpt, 'reach' => 'national']);
        $near = $this->dSignal(['topics' => ['plumbing'], 'place' => $this->geo->sandton, 'reach' => 'national']);

        $this->matcher()->forUser($homebody);

        $this->assertNull(PulseMatch::firstWhere('signal_id', $far->id));
        $this->assertNotNull(PulseMatch::firstWhere('signal_id', $near->id));
    }

    public function test_remote_work_ignores_distance(): void
    {
        $remote = $this->dUser(['skills' => ['Software development'], 'place' => $this->geo->cpt, 'reach' => 'remote']);
        $employer = $this->dUser();
        $entity = $this->dEntity($employer, ['is_employer' => true]);
        $signal = $this->dSignal(['kind' => 'role', 'subject_type' => 'role', 'entity_id' => $entity->id, 'owner_id' => $employer->id, 'skills' => ['software-development'], 'place' => $this->geo->lagos, 'reach' => 'local', 'is_remote' => true]);

        $reasons = $this->reasonsFor($remote, $signal);

        $this->assertArrayHasKey('skill_role', $reasons);
        $this->assertArrayHasKey('remote', $reasons);
        $this->assertArrayNotHasKey('place_city', $reasons);
    }

    public function test_following_a_business_surfaces_it_even_outside_reach(): void
    {
        $u = $this->dUser(['place' => $this->geo->jhb, 'reach' => 'city']);
        $entity = $this->dEntity($this->dUser(), ['name' => 'Karoo Bakery']);
        $signal = $this->dOfferSignal($entity, ['baking'], ['place' => $this->geo->cpt, 'reach' => 'local']);
        $this->assertSame([], $this->reasonsFor($u, $signal));

        $this->dFollowEntity($u, $entity);
        $reasons = $this->reasonsFor($u, $signal);

        $this->assertSame('You follow Karoo Bakery.', $reasons['follows']['text']);
        $this->assertSame((string) $entity->id, $reasons['follows']['ref']);
    }

    public function test_following_a_person_names_them(): void
    {
        $author = $this->dUser([], ['name' => 'Thandi Mokoena']);
        $reader = $this->dUser(['place' => $this->geo->jhb]);
        DB::table('pulse_followers')->insert(['follower_id' => $reader->id, 'following_id' => $author->id, 'created_at' => now(), 'updated_at' => now()]);
        $post = $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $author->id, 'topics' => []]);

        $reasons = $this->reasonsFor($reader, $post);

        $this->assertSame('You follow Thandi Mokoena.', $reasons['follows']['text']);
        $this->assertSame('user:'.$author->id, $reasons['follows']['ref']);
    }

    public function test_a_shared_community_is_a_reason_but_never_enough_alone(): void
    {
        $author = $this->dUser();
        $reader = $this->dUser(['interests' => ['plumbing']]);
        $community = Community::factory()->create();
        foreach ([$author, $reader] as $user) {
            DB::table('community_memberships')->insert(['community_id' => $community->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        }
        $onTopic = $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $author->id, 'topics' => ['plumbing']]);
        $offTopic = $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $author->id, 'topics' => ['welding']]);

        $this->assertArrayHasKey('mutual_context', $this->reasonsFor($reader, $onTopic));
        $this->assertSame([], $this->reasonsFor($reader, $offTopic));
    }

    public function test_fresh_decays_and_stops_after_two_weeks(): void
    {
        $u = $this->dUser(['interests' => ['plumbing']]);
        $new = $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id, 'topics' => ['plumbing'], 'published_at' => now()->subHours(3)]);
        $old = $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id, 'topics' => ['plumbing'], 'published_at' => now()->subDays(20)]);

        $this->assertSame('Posted today.', $this->reasonsFor($u, $new)['fresh']['text']);
        $this->assertArrayNotHasKey('fresh', $this->reasonsFor($u, $old));
        $this->assertGreaterThan(PulseMatch::firstWhere('signal_id', $old->id)->score, PulseMatch::firstWhere('signal_id', $new->id)->score);
    }

    public function test_responsive_comes_only_from_real_conversation_stats(): void
    {
        $u = $this->dUser(['interests' => ['plumbing'], 'place' => $this->geo->jhb]);
        $entity = $this->dEntity($this->dUser());
        $signal = $this->dOfferSignal($entity, ['plumbing'], ['place' => $this->geo->jhb]);
        $this->assertArrayNotHasKey('responsive', $this->reasonsFor($u, $signal), 'no conversations, no claim');

        $asker = $this->dUser();
        foreach (range(1, 4) as $i) {
            DB::table('pulse_conversations')->insert(['entity_id' => $entity->id, 'created_at' => now()->subDays($i), 'updated_at' => now(), 'first_response_at' => now()->subDays($i)->addHours(2)]);
        }

        $this->assertSame('They usually reply within a day.', $this->reasonsFor($u, $signal)['responsive']['text']);
    }

    public function test_a_signal_with_no_reasons_is_not_stored(): void
    {
        $u = $this->dUser(['interests' => ['plumbing'], 'place' => $this->geo->jhb]);
        $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id, 'topics' => ['welding'], 'place' => $this->geo->cpt]);

        $this->assertSame(0, $this->matcher()->forUser($u));
        $this->assertSame(0, PulseMatch::count());
    }

    public function test_it_never_matches_your_own_things_or_blocked_muted_or_unavailable_ones(): void
    {
        $u = $this->dUser(['interests' => ['plumbing'], 'place' => $this->geo->jhb]);
        $mine = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $u->id]);
        $ownEntity = $this->dEntity($u);
        $myPage = $this->dOfferSignal($ownEntity, ['plumbing']);

        $blocked = $this->dUser();
        $this->dBlock($u, $blocked);
        $fromBlocked = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $blocked->id]);

        $blocker = $this->dUser();
        $this->dBlock($blocker, $u);
        $fromBlocker = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $blocker->id]);

        $mutedEntity = $this->dEntity($this->dUser());
        $this->dMute($u, 'entity', (string) $mutedEntity->id);
        $fromMuted = $this->dOfferSignal($mutedEntity, ['plumbing']);

        $mutedUser = $this->dUser();
        $this->dMute($u, 'user', (string) $mutedUser->id);
        $fromMutedUser = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $mutedUser->id]);

        $this->dMute($u, 'topic', 'Welding');
        $weldy = $this->dSignal(['topics' => ['plumbing', 'welding'], 'owner_id' => $this->dUser()->id]);

        $held = $this->dSignal(['topics' => ['plumbing'], 'status' => 'held', 'owner_id' => $this->dUser()->id]);
        $removed = $this->dSignal(['topics' => ['plumbing'], 'status' => 'removed', 'owner_id' => $this->dUser()->id]);
        $expired = $this->dSignal(['topics' => ['plumbing'], 'expires_at' => now()->subMinute(), 'owner_id' => $this->dUser()->id]);
        $paused = $this->dSignal(['topics' => ['plumbing'], 'status' => 'paused', 'owner_id' => $this->dUser()->id]);
        $okay = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $this->dUser()->id]);

        $this->matcher()->forUser($u);

        $matched = PulseMatch::where('user_id', $u->id)->pluck('signal_id')->all();
        $this->assertSame([$okay->id], $matched);
    }

    public function test_a_suspended_or_draft_business_never_matches(): void
    {
        $u = $this->dUser(['interests' => ['plumbing']]);
        $draft = $this->dEntity($this->dUser(), ['status' => 'draft']);
        $signal = $this->dOfferSignal($draft, ['plumbing']);

        $this->assertSame([], $this->reasonsFor($u, $signal));
    }

    public function test_recompute_keeps_dismissal_and_seen_state_and_drops_stale_rows(): void
    {
        $u = $this->dUser(['interests' => ['plumbing']]);
        $s = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $this->dUser()->id]);
        $this->matcher()->forUser($u);
        PulseMatch::firstWhere('signal_id', $s->id)->forceFill(['dismissed_at' => now(), 'dismissed_reason' => 'not_for_me', 'seen_at' => now()])->save();

        $this->matcher()->forUser($u);

        $m = PulseMatch::firstWhere('signal_id', $s->id);
        $this->assertNotNull($m->dismissed_at);
        $this->assertSame('not_for_me', $m->dismissed_reason);

        $stale = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $this->dUser()->id]);
        $this->matcher()->forUser($u);
        $this->assertNotNull(PulseMatch::firstWhere('signal_id', $stale->id));
        $stale->update(['status' => 'removed']);
        $this->matcher()->forUser($u);
        $this->assertNull(PulseMatch::firstWhere('signal_id', $stale->id));
    }

    public function test_a_new_signal_is_matched_against_interested_people(): void
    {
        $near = $this->dUser(['interests' => ['plumbing'], 'place' => $this->geo->sandton]);
        $far = $this->dUser(['interests' => ['plumbing'], 'place' => $this->geo->cpt]);
        $seller = $this->dUser();
        $signal = $this->dOfferSignal($this->dEntity($seller), ['plumbing'], ['place' => $this->geo->jhb, 'reach' => 'local']);

        $written = $this->matcher()->forSignal($signal);

        $this->assertSame(1, $written);
        $this->assertNotNull(PulseMatch::where('user_id', $near->id)->first());
        $this->assertNull(PulseMatch::where('user_id', $far->id)->first());

        // Pulling the signal removes its un-dismissed matches.
        $signal->update(['status' => 'removed']);
        $this->matcher()->forSignal($signal->fresh());
        $this->assertSame(0, PulseMatch::count());
    }

    public function test_the_feature_set_contains_no_protected_ground_or_proxy(): void
    {
        $forbidden = ['age', 'birth', 'dob', 'gender', 'sex', 'race', 'ethnic', 'religion', 'faith', 'health', 'disab', 'marital', 'married', 'pregnan', 'postcode', 'postal', 'zip', 'nationality', 'language_home', 'surname', 'name'];

        $check = function (string $label, string $word) use ($forbidden) {
            $w = strtolower($word);
            foreach ($forbidden as $bad) {
                // "experience_band" and friends are fine; word-ish matches on the field names are not.
                $this->assertDoesNotMatchRegularExpression('/(^|[._])'.$bad.'([._]|$)|^'.$bad.'/', $w, "{$label} '{$word}' looks like a forbidden feature '{$bad}'");
            }
        };

        foreach (Matcher::FEATURES as $feature) {
            $check('feature', $feature);
        }

        foreach ((new \ReflectionClass(UserContext::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $check('UserContext property', $prop->getName());
        }

        // The profile columns matching reads are an explicit list too.
        foreach (ContextLoader::PROFILE_COLUMNS as $col) {
            $check('profile column', $col);
        }

        $this->assertNotContains('profile.age', Matcher::FEATURES);
    }

    public function test_matching_ignores_everything_not_in_the_feature_list(): void
    {
        // Two people who differ only in fields matching must not read give identical matches.
        $a = $this->dUser(['interests' => ['plumbing'], 'place' => $this->geo->jhb], ['name' => 'Aaron A']);
        $b = $this->dUser(['interests' => ['plumbing'], 'place' => $this->geo->jhb], ['name' => 'Zodwa Z']);
        DB::table('pulse_profiles')->where('user_id', $b->id)->update(['bio' => 'anything', 'headline' => 'Different', 'location' => 'Elsewhere 0001']);
        $s = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $this->dUser()->id]);

        $this->matcher()->forUser($a);
        $this->matcher()->forUser($b);

        $this->assertSame(
            PulseMatch::where('user_id', $a->id)->first()->reasons,
            PulseMatch::where('user_id', $b->id)->first()->reasons,
        );
    }

    public function test_matching_never_rejects_a_person_it_only_chooses_what_to_show(): void
    {
        // A person with no profile data at all simply has no matches, and nothing throws.
        $blank = $this->dUser();
        $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $this->dUser()->id]);

        $this->assertSame(0, $this->matcher()->forUser($blank));
    }
}

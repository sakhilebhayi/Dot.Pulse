<?php

namespace Tests\Feature\Entities;

use App\Models\PulseMedia;
use App\Models\PulseProfile;
use App\Models\PulseSignal;
use App\Services\Pulse\Entities\ProfileService;
use Illuminate\Validation\ValidationException;

class ProfileServiceTest extends EntitiesTestCase
{
    private function service(): ProfileService
    {
        return app(ProfileService::class);
    }

    private function signal(PulseProfile $profile): ?PulseSignal
    {
        return PulseSignal::where('subject_type', 'profile')->where('subject_id', $profile->id)->first();
    }

    private function readyProfile($user): PulseProfile
    {
        $this->service()->update($user, ['headline' => 'Senior accountant', 'skills' => ['IFRS', 'Audit'], 'experience_years' => 8, 'kind' => 'professional']);

        return $this->service()->confirmAge($user);
    }

    public function test_update_creates_the_profile_and_sanitises(): void
    {
        $user = $this->person();

        $profile = $this->service()->update($user, [
            'headline' => '  <b>Senior</b>   accountant ',
            'bio' => '<script>x</script>Twelve years of audit.',
            'skills' => ['IFRS', 'ifrs', ' Audit ', '', '<i>Tax</i>'],
            'languages' => ['English', 'isiZulu'],
            'experience_years' => 12,
        ]);

        $this->assertSame('Senior accountant', $profile->headline);
        $this->assertSame('xTwelve years of audit.', $profile->bio);
        $this->assertSame(['IFRS', 'Audit', 'Tax'], $profile->skills);
        $this->assertSame('customer', $profile->role);
        $this->assertSame(1, PulseProfile::where('user_id', $user->id)->count());
    }

    public function test_the_platform_role_cannot_be_written_through_the_service(): void
    {
        $user = $this->person();

        $profile = $this->service()->update($user, ['headline' => 'Hello there', 'role' => 'admin', 'community_points' => 9999, 'is_verified' => true]);

        $this->assertSame('customer', $profile->role);
        $this->assertSame(0, $profile->community_points);
        $this->assertFalse($profile->is_verified);
    }

    public function test_an_existing_moderator_role_survives_an_edit(): void
    {
        $moderator = $this->moderator();

        $this->service()->update($moderator, ['headline' => 'Keeping the peace']);

        $this->assertSame('moderator', $moderator->fresh()->pulseProfile->role);
    }

    public function test_validation_rejects_bad_values(): void
    {
        foreach ([['experience_years' => 80], ['contact_policy' => 'everyone'], ['reach' => 'galaxy'], ['kind' => 'robot'], ['open_to' => ['golf']]] as $bad) {
            try {
                $this->service()->update($this->person(), $bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_discoverability_needs_age_confirmation_a_headline_and_a_skill(): void
    {
        $user = $this->person();

        foreach (['anonymous', 'open'] as $mode) {
            try {
                $this->service()->setDiscoverability($user, $mode);
                $this->fail('needs age');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('18', $e->errors()['discoverability'][0]);
            }
        }

        $this->service()->confirmAge($user);

        try {
            $this->service()->setDiscoverability($user, 'open');
            $this->fail('needs headline and skills');
        } catch (ValidationException) {
        }

        $this->service()->update($user, ['headline' => 'Senior accountant', 'skills' => ['IFRS']]);
        $this->assertSame('open', $this->service()->setDiscoverability($user, 'open')->discoverability);
    }

    public function test_off_is_always_allowed_and_unknown_modes_are_not(): void
    {
        $this->assertSame('off', $this->service()->setDiscoverability($this->person(), 'off')->discoverability);

        $this->expectException(ValidationException::class);
        $this->service()->setDiscoverability($this->person(), 'public');
    }

    public function test_the_talent_signal_follows_discoverability(): void
    {
        $user = $this->person();
        $profile = $this->readyProfile($user);

        $this->assertNull($this->signal($profile));

        $this->service()->setDiscoverability($user, 'anonymous');
        $this->assertTrue($this->signal($profile)->anonymous);
        $this->assertSame('live', $this->signal($profile)->status);

        $this->service()->setDiscoverability($user, 'open');
        $this->assertFalse($this->signal($profile)->anonymous);

        $this->service()->setDiscoverability($user, 'off');
        $this->assertSame('removed', $this->signal($profile)->status);
    }

    public function test_a_hold_verdict_on_the_headline_hides_the_talent_signal(): void
    {
        $user = $this->person();
        $this->readyProfile($user);
        $this->rules(Fakes\HoldRules::class);

        $profile = $this->service()->setDiscoverability($user, 'open');

        $this->assertSame('held', $profile->moderation);
        $this->assertSame('held', $this->signal($profile)->status);
    }

    public function test_open_to_contact_policy_and_onboarding(): void
    {
        $user = $this->person();

        $this->assertSame(['work', 'mentoring'], $this->service()->setOpenTo($user, ['work', 'mentoring', 'work'])->open_to);
        $this->assertSame('requests', $this->service()->setContactPolicy($user, 'requests')->contact_policy);

        try {
            $this->service()->setOpenTo($user, ['golf']);
            $this->fail('unknown open_to');
        } catch (ValidationException) {
        }

        try {
            $this->service()->completeOnboarding($user);
            $this->fail('onboarding needs the age confirmation');
        } catch (ValidationException) {
            $this->assertNull($user->fresh()->pulseProfile->onboarded_at);
        }

        $done = $this->service()->completeOnboarding($user, ageConfirmed: true);
        $this->assertNotNull($done->onboarded_at);
        $this->assertNotNull($done->age_confirmed_at);
    }

    public function test_avatar_must_be_the_persons_own_avatar_image(): void
    {
        $user = $this->person();
        $other = $this->person();
        $mine = PulseMedia::create(['user_id' => $user->id, 'purpose' => 'avatar', 'disk' => 'local', 'mime' => 'image/webp', 'width' => 10, 'height' => 10, 'bytes' => 1, 'variants' => []]);
        $theirs = PulseMedia::create(['user_id' => $other->id, 'purpose' => 'avatar', 'disk' => 'local', 'mime' => 'image/webp', 'width' => 10, 'height' => 10, 'bytes' => 1, 'variants' => []]);

        $this->assertSame($mine->id, $this->service()->setAvatar($user, $mine->id)->avatar_media_id);

        $this->expectException(ValidationException::class);
        $this->service()->setAvatar($user, $theirs->id);
    }

    // ---- the card ---------------------------------------------------------------------------------------------

    private function anonymousPerson(): array
    {
        $city = $this->place('city', 'Sandton');
        $this->place('region', 'Gauteng');
        $user = $this->person(['name' => 'Thandiwe Nkosi', 'email' => 'thandiwe.nkosi@example.org', 'profile_photo_path' => 'photos/thandiwe.jpg']);
        $this->service()->update($user, [
            'headline' => 'Thandiwe Nkosi - senior accountant (thandiwe.nkosi@example.org)',
            'bio' => 'Thandiwe has twelve years in audit. Phone 082 555 0147.',
            'skills' => ['IFRS', 'Audit'],
            'experience_years' => 12,
            'place_id' => $city->id,
            'languages' => ['English'],
            'achievements' => ['Led the 2024 audit at Acme'],
            'work_history' => [['title' => 'Auditor', 'org' => 'Acme', 'from_year' => 2015, 'current' => true]],
        ]);
        $this->service()->confirmAge($user);
        $this->service()->setOpenTo($user, ['work']);
        $profile = $this->service()->setDiscoverability($user, 'anonymous');

        return [$user, $profile];
    }

    public function test_an_anonymous_card_leaks_nothing_to_anyone_else(): void
    {
        [$user, $profile] = $this->anonymousPerson();
        $employer = $this->person();
        $moderator = $this->moderator();
        $this->service()->setAvatar($user, PulseMedia::create(['user_id' => $user->id, 'purpose' => 'avatar', 'disk' => 'local', 'mime' => 'image/webp', 'width' => 10, 'height' => 10, 'bytes' => 1, 'variants' => []])->id);

        foreach ([$employer, $moderator, null] as $viewer) {
            $card = $this->service()->card($profile->fresh(), $viewer);
            $json = json_encode($card);

            foreach (['Thandiwe', 'Nkosi', 'thandiwe', 'nkosi', 'example.org', '0147', 'Sandton', 'photos/', '/media/'] as $leak) {
                $this->assertStringNotContainsString($leak, $json, "card leaks '$leak' to ".($viewer?->name ?? 'a guest'));
            }

            foreach (['name', 'email', 'avatar_url', 'user_id', 'bio', 'place', 'work_history', 'achievements', 'interests'] as $key) {
                $this->assertArrayNotHasKey($key, $card);
            }

            $this->assertTrue($card['anonymous']);
            $this->assertSame(['IFRS', 'Audit'], $card['skills']);
            $this->assertSame('10_plus', $card['experience_band']['key']);
            $this->assertSame('Gauteng', $card['region']);
            $this->assertSame(['work'], $card['open_to']);
            $this->assertSame($this->signal($profile)->id, $card['signal_id']);
            $this->assertFalse($card['is_self']);
        }
    }

    public function test_the_owner_sees_their_whole_card(): void
    {
        [$user, $profile] = $this->anonymousPerson();

        $card = $this->service()->card($profile->fresh(), $user);

        $this->assertTrue($card['is_self']);
        $this->assertSame('Thandiwe Nkosi', $card['name']);
        $this->assertSame('anonymous', $card['discoverability']);
        $this->assertSame('Sandton', $card['place']);
        $this->assertCount(1, $card['work_history']);
    }

    public function test_an_open_card_shows_the_person_to_signed_in_people_only(): void
    {
        [$user] = $this->anonymousPerson();
        $profile = $this->service()->setDiscoverability($user, 'open');
        $viewer = $this->person();

        $card = $this->service()->card($profile, $viewer);

        $this->assertFalse($card['anonymous']);
        $this->assertSame('Thandiwe Nkosi', $card['name']);
        $this->assertSame($user->id, $card['user_id']);
        $this->assertCount(1, $card['work_history']);

        $guest = $this->service()->card($profile, null);
        $this->assertArrayNotHasKey('name', $guest);
        $this->assertStringNotContainsString('Thandiwe', json_encode($guest));
    }

    public function test_a_profile_that_is_off_is_an_ordinary_profile_without_the_talent_extras(): void
    {
        $user = $this->person(['name' => 'Sipho Dube']);
        $profile = $this->service()->update($user, ['headline' => 'Plumber', 'skills' => ['Plumbing'], 'work_history' => [['title' => 'Plumber', 'org' => 'Self']]]);
        $this->service()->setOpenTo($user, ['work']);

        $card = $this->service()->card($profile->fresh(), $this->person());

        $this->assertSame('Sipho Dube', $card['name']);
        $this->assertSame([], $card['open_to']);
        $this->assertSame([], $card['work_history']);
        $this->assertNull($card['signal_id']);
    }

    public function test_show_place_off_hides_the_place_even_from_signed_in_people(): void
    {
        $user = $this->person();
        $this->service()->update($user, ['headline' => 'Plumber', 'place_id' => $this->place()->id, 'show_place' => false]);

        $card = $this->service()->card($user->fresh()->pulseProfile, $this->person());

        $this->assertNull($card['place']);
        $this->assertNull($card['region']);
    }
}

<?php

namespace Tests\Feature\Security;

use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Models\User;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Entities\NeedService;
use App\Services\Pulse\Entities\OfferService;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Entities\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Every service takes an array that a form built. Whatever extra keys ride along (an owner, a moderation state, a
 * verification state, a role, points, the age declaration) must not reach the row.
 */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use SecurityWorld;

    private const EVIL = [
        'user_id' => 999, 'created_by' => 999, 'owner_id' => 999, 'entity_id' => 999, 'id' => 999,
        'status' => 'live', 'moderation' => 'approved', 'verification_status' => 'infodot_verified', 'verified_at' => '2020-01-01 00:00:00',
        'verification_source' => 'infodot', 'is_verified' => true, 'role' => 'admin', 'community_points' => 9999, 'hiring_signal' => 'none',
        'deleted_at' => null, 'published_at' => '2020-01-01 00:00:00', 'age_confirmed_at' => '2001-01-01 00:00:00', 'onboarded_at' => '2001-01-01 00:00:00',
        'discoverability' => 'open', 'slug' => 'taken-over', 'infodot_business_ref' => 'someone-elses-company',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->buildWorld();
    }

    public function test_a_page_cannot_be_created_or_updated_into_a_state_the_services_did_not_choose(): void
    {
        $me = $this->w('stranger');
        $entities = app(EntityService::class);

        $page = $entities->create($me, ['name' => 'Fresh Page Pty Ltd', 'is_seller' => true] + self::EVIL);

        $this->assertSame($me->id, $page->created_by);
        $this->assertNotSame('infodot_verified', $page->verification_status);
        $this->assertNull($page->verification_source);
        $this->assertNull($page->verified_at);
        $this->assertNotSame('taken-over', $page->slug);
        $this->assertNull($page->infodot_business_ref);

        $before = $this->w('seller')->only(['created_by', 'moderation', 'slug', 'infodot_business_ref']);
        $entities->update($this->w('sellerOwner'), $this->w('seller'), ['name' => 'Moyo Plumbing Works', 'is_seller' => true] + self::EVIL);
        $this->assertSame($before, $this->w('seller')->fresh()->only(array_keys($before)));
        $this->assertNotSame('infodot_verified', $this->w('seller')->fresh()->verification_status);
    }

    public function test_offers_roles_and_needs_keep_their_owner_and_their_moderation_state(): void
    {
        $owner = $this->w('sellerOwner');
        $seller = $this->w('seller');

        $offer = app(OfferService::class)->create($owner, $seller, ['title' => 'A brand new offer title', 'summary' => 'Plain words', 'price_mode' => 'quote'] + self::EVIL);
        $this->assertSame($seller->id, $offer->entity_id);
        $this->assertSame($owner->id, $offer->created_by);
        $this->assertSame('draft', $offer->status);
        $this->assertSame('pending', $offer->moderation);

        $employer = $this->w('employer');
        $role = app(RoleService::class)->create($this->w('employerOwner'), $employer, ['title' => 'A brand new role title', 'summary' => 'Plain words'] + self::EVIL);
        $this->assertSame($employer->id, $role->entity_id);
        $this->assertSame($this->w('employerOwner')->id, $role->created_by);
        $this->assertNotSame('approved', $role->moderation);
        $this->assertNotSame('live', $role->status);

        $updated = app(OfferService::class)->update($owner, $this->w('heldOffer'), ['title' => 'Held offer text', 'summary' => 'Plain words'] + self::EVIL);
        $this->assertSame('pending', $updated->moderation, 'an author cannot approve their own listing through an update');
        $this->assertSame($seller->id, $updated->entity_id);

        $need = app(NeedService::class)->create($this->w('stranger'), ['title' => 'A brand new need title', 'details' => 'Plain words'] + array_diff_key(self::EVIL, ['entity_id' => 1]));
        $this->assertSame($this->w('stranger')->id, $need->user_id);
        $this->assertNull($need->entity_id);

        // A page that is not yours cannot be named as the poster, by id.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(NeedService::class)->create($this->w('stranger'), ['title' => 'Another need title here', 'details' => 'Plain words', 'entity_id' => $seller->id]);

    }

    public function test_a_profile_update_cannot_grant_a_role_points_the_age_declaration_or_discoverability(): void
    {
        $me = $this->w('minor');
        $before = PulseProfile::query()->where('user_id', $me->id)->first();

        app(ProfileService::class)->update($me, ['headline' => 'Plain headline'] + self::EVIL);

        $after = PulseProfile::query()->where('user_id', $me->id)->first();
        $this->assertSame('Plain headline', $after->headline);
        $this->assertSame($before->role, $after->role);
        $this->assertSame(0, (int) $after->community_points);
        $this->assertFalse((bool) $after->is_verified);
        $this->assertNull($after->age_confirmed_at, 'the age declaration only comes from the person saying so');
        $this->assertSame('off', $after->discoverability);
        $this->assertFalse($me->fresh()->isPlatformModerator());
    }

    public function test_the_legacy_profile_form_cannot_make_anyone_a_moderator(): void
    {
        $me = $this->w('stranger');

        \Livewire\Livewire::actingAs($me)->test(\App\Livewire\Profile\UpdatePulseProfileForm::class)
            ->set('headline', 'Plain')->call('save');

        $this->assertFalse($me->fresh()->isPlatformModerator());
    }

    public function test_an_image_id_that_belongs_to_somebody_else_cannot_be_hung_on_my_page_profile_or_offer(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $image = fn () => \Illuminate\Http\UploadedFile::fake()->image('a.png', 64, 64);
        $media = app(\App\Services\Pulse\Entities\MediaService::class);

        // A private photo from someone else's conversation, and another page's logo.
        $private = $media->store($this->w('stranger'), $image(), 'message', null, $this->w('line')->id);
        $theirLogo = $media->store($this->w('sellerOwner'), $image(), 'logo', $this->w('seller'));

        $me = $this->w('employerOwner');
        $employer = $this->w('employer');

        foreach ([$private, $theirLogo] as $id) {
            try {
                app(EntityService::class)->update($me, $employer, ['name' => 'Acme Works Pty', 'is_employer' => true, 'logo_media_id' => $id]);
                $this->fail('hung a foreign image on a page');
            } catch (ValidationException) {
                $this->assertNull($employer->fresh()->logo_media_id);
            }

            try {
                app(ProfileService::class)->setAvatar($me, $id);
                $this->fail('hung a foreign image on a profile');
            } catch (ValidationException) {
                $this->assertNull(PulseProfile::query()->where('user_id', $me->id)->value('avatar_media_id'));
            }
        }

        $this->expectException(ValidationException::class);
        app(OfferService::class)->update($this->w('sellerOwner'), $this->w('offer'), ['media_ids' => [$private]]);
    }
}

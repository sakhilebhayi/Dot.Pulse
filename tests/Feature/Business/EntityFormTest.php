<?php

namespace Tests\Feature\Business;

use App\Livewire\Pulse\Business\EntityForm;
use App\Models\PulseEntity;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

class EntityFormTest extends BusinessTestCase
{
    public function test_create_publishes_a_page_and_lands_on_manage(): void
    {
        $owner = $this->infodotPerson();
        $place = $this->place();

        Livewire::actingAs($owner)->test(EntityForm::class)
            ->set('name', 'Moyo Plumbing')
            ->set('tagline', 'Geysers fixed the same day')
            ->set('about', 'A family plumbing business in Soweto.')
            ->set('is_seller', true)
            ->set('industry', 'Plumbing')
            ->call('choosePlace', $place->id)
            ->set('service_area_text', 'Gauteng, remote')
            ->set('team_size', '2-10')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('entities.manage', ['entity' => 'moyo-plumbing']));

        $page = PulseEntity::where('slug', 'moyo-plumbing')->firstOrFail();
        $this->assertSame('live', $page->status);
        $this->assertSame('approved', $page->moderation);
        $this->assertSame($place->id, $page->place_id);
        $this->assertSame(['Gauteng', 'remote'], $page->service_area);
        $this->assertSame('owner', $page->memberRole($owner));
    }

    public function test_save_as_draft_leaves_the_page_off_the_floor(): void
    {
        $owner = $this->infodotPerson();

        Livewire::actingAs($owner)->test(EntityForm::class)
            ->set('name', 'Quiet Studio')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $page = PulseEntity::where('name', 'Quiet Studio')->firstOrFail();
        $this->assertSame('draft', $page->status);
        $this->app['auth']->forgetGuards();
        $this->get(route('entities.show', ['entity' => $page->slug]))->assertNotFound();

        Livewire::actingAs($owner)->test(EntityForm::class, ['entity' => $page])->assertSee('Draft')->assertSee('Publish the page')->call('putOnFloor');
        $this->assertSame('live', $page->fresh()->status);
    }

    public function test_a_new_author_without_an_infodot_company_is_waiting_for_a_human(): void
    {
        $owner = $this->person();

        Livewire::actingAs($owner)->test(EntityForm::class)
            ->set('name', 'Fresh Start')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('pending', PulseEntity::where('name', 'Fresh Start')->value('moderation'));
    }

    public function test_a_page_must_sell_hire_or_both(): void
    {
        Livewire::actingAs($this->person())->test(EntityForm::class)
            ->set('name', 'Nothing Inc')->set('is_seller', false)->set('is_employer', false)
            ->call('save')
            ->assertHasErrors(['is_seller']);
    }

    public function test_name_and_website_are_validated(): void
    {
        Livewire::actingAs($this->person())->test(EntityForm::class)
            ->set('name', '')->call('save')->assertHasErrors(['name']);

        Livewire::actingAs($this->person())->test(EntityForm::class)
            ->set('name', 'Valid Name')->set('website', 'javascript:alert(1)')->call('save')->assertHasErrors(['website']);
    }

    public function test_the_quota_is_explained_and_enforced(): void
    {
        config(['pulse.entities.max_per_user' => 1]);
        $owner = $this->infodotPerson();
        $this->page($owner, ['name' => 'First Page']);

        Livewire::actingAs($owner)->test(EntityForm::class)
            ->assertSee('the most one person can')->assertSee('First Page')
            ->set('name', 'Second Page')
            ->call('save')
            ->assertHasErrors(['name']);

        $this->assertDatabaseMissing('pulse_entities', ['name' => 'Second Page']);
    }

    public function test_a_company_the_person_belongs_to_can_be_linked_on_create(): void
    {
        $owner = $this->person();
        $team = $this->company($owner, 'Acme Holdings');

        $c = Livewire::actingAs($owner->fresh())->test(EntityForm::class)
            ->assertSee('Acme Holdings')
            ->set('name', 'Acme Trading')->set('infodot_team', $team->infodot_team_uuid)
            ->call('save')->assertHasNoErrors();

        $page = PulseEntity::where('name', 'Acme Trading')->firstOrFail();
        $this->assertSame($team->infodot_team_uuid, $page->infodot_business_ref);
        $this->assertSame('infodot_member', $page->verification_status);
    }

    public function test_a_company_belonging_to_someone_else_cannot_be_linked(): void
    {
        $owner = $this->person();
        $other = $this->person();
        $theirs = $this->company($other, 'Their Company');

        Livewire::actingAs($owner)->test(EntityForm::class)
            ->set('name', 'Sneaky')->set('infodot_team', $theirs->infodot_team_uuid)
            ->call('save')->assertHasErrors(['infodot_team']);

        $this->assertNull(PulseEntity::where('name', 'Sneaky')->value('infodot_business_ref'));
    }

    public function test_registration_number_is_saved_as_a_claim_only(): void
    {
        $owner = $this->infodotPerson();

        Livewire::actingAs($owner)->test(EntityForm::class)
            ->set('name', 'Claimant')->set('registration_number', '2019/123456/07')
            ->call('save')->assertHasNoErrors();

        $page = PulseEntity::where('name', 'Claimant')->firstOrFail();
        $this->assertSame('2019/123456/07', $page->registration_number);
        $this->assertArrayHasKey('registration', (array) $page->claims);
        $this->assertNotSame('infodot_verified', $page->verification_status);
    }

    public function test_a_logo_and_cover_upload_and_attach(): void
    {
        $owner = $this->infodotPerson();

        Livewire::actingAs($owner)->test(EntityForm::class)
            ->set('name', 'With Pictures')
            ->set('logo', UploadedFile::fake()->image('logo.png', 300, 300))
            ->set('cover', UploadedFile::fake()->image('cover.jpg', 1200, 400))
            ->call('save')
            ->assertHasNoErrors();

        $page = PulseEntity::where('name', 'With Pictures')->firstOrFail();
        $this->assertNotNull($page->logo_media_id);
        $this->assertNotNull($page->cover_media_id);
        $this->assertDatabaseHas('pulse_media_files', ['id' => $page->logo_media_id, 'purpose' => 'logo', 'entity_id' => $page->id]);
        $this->assertDatabaseHas('pulse_media_files', ['id' => $page->cover_media_id, 'purpose' => 'cover']);
    }

    public function test_a_non_image_is_rejected(): void
    {
        $owner = $this->infodotPerson();

        Livewire::actingAs($owner)->test(EntityForm::class)
            ->set('name', 'Bad Upload')
            ->set('logo', UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['logo']);

        $this->assertDatabaseMissing('pulse_entities', ['name' => 'Bad Upload']);
    }

    public function test_a_disguised_file_is_rejected_by_its_bytes(): void
    {
        $owner = $this->infodotPerson();
        $fake = UploadedFile::fake()->createWithContent('logo.png', '<?php echo "not an image";');

        Livewire::actingAs($owner)->test(EntityForm::class)
            ->set('name', 'Disguised')
            ->set('logo', $fake)
            ->call('save')
            ->assertHasErrors(['logo']);
    }

    public function test_only_owners_and_admins_can_edit_the_page(): void
    {
        $page = $this->page();
        $admin = $this->member($page, 'admin');
        $editor = $this->member($page, 'editor');
        $stranger = $this->person();

        Livewire::actingAs($stranger)->test(EntityForm::class, ['entity' => $page])->assertForbidden();
        Livewire::actingAs($editor)->test(EntityForm::class, ['entity' => $page])->assertForbidden();

        Livewire::actingAs($admin)->test(EntityForm::class, ['entity' => $page])
            ->set('tagline', 'New tagline')->call('save')->assertHasNoErrors();
        $this->assertSame('New tagline', $page->fresh()->tagline);
    }

    public function test_the_owner_can_take_the_page_off_the_floor_and_back_but_an_admin_cannot(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $admin = $this->member($page, 'admin');

        Livewire::actingAs($admin)->test(EntityForm::class, ['entity' => $page])->call('takeOff');
        $this->assertSame('live', $page->fresh()->status);

        Livewire::actingAs($owner)->test(EntityForm::class, ['entity' => $page])->call('takeOff');
        $this->assertSame('archived', $page->fresh()->status);

        Livewire::actingAs($owner)->test(EntityForm::class, ['entity' => $page->fresh()])->call('putOnFloor');
        $this->assertSame('live', $page->fresh()->status);
    }

    public function test_a_held_page_says_why_in_the_editor(): void
    {
        $page = $this->page(null, ['moderation' => 'pending']);
        $owner = $page->members()->first()->user;

        Livewire::actingAs($owner)->test(EntityForm::class, ['entity' => $page])->assertSee('Waiting for a human');
    }

    public function test_the_create_route_renders_for_members_and_redirects_guests(): void
    {
        $this->get(route('entities.create'))->assertRedirect();
        $this->actingAs($this->person())->get(route('entities.create'))->assertOk()->assertSeeLivewire(EntityForm::class);
    }

    public function test_replacing_a_logo_removes_the_old_file(): void
    {
        $owner = $this->infodotPerson();
        $page = $this->page($owner);

        $c = Livewire::actingAs($owner)->test(EntityForm::class, ['entity' => $page])
            ->set('logo', UploadedFile::fake()->image('a.png', 200, 200))->call('save');
        $first = $page->fresh()->logo_media_id;
        $this->assertNotNull($first);

        Livewire::actingAs($owner)->test(EntityForm::class, ['entity' => $page->fresh()])
            ->set('logo', UploadedFile::fake()->image('b.png', 200, 200))->call('save');

        $second = $page->fresh()->logo_media_id;
        $this->assertNotSame($first, $second);
        $this->assertDatabaseMissing('pulse_media_files', ['id' => $first]);
    }
}

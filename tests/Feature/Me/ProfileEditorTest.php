<?php

namespace Tests\Feature\Me;

use App\Livewire\Pulse\Me\ProfileEditor;
use App\Models\PulseMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class ProfileEditorTest extends MeTestCase
{
    public function test_the_page_needs_sign_in_and_the_welcome_flow(): void
    {
        $this->get('/me/profile')->assertRedirect('/login');
        $this->actingAs($this->newcomer())->get('/me/profile')->assertRedirect(route('onboarding'));
        $this->actingAs($this->member())->get('/me/profile')->assertOk()->assertSee('Your photo');
        $this->actingAs($this->member())->get('/me')->assertRedirect('/me/profile');
    }

    public function test_it_loads_what_is_saved_and_saves_through_the_service(): void
    {
        $u = $this->member(['headline' => 'Old line', 'skills' => json_encode(['plumbing'])]);

        Livewire::actingAs($u)->test(ProfileEditor::class)
            ->assertSet('headline', 'Old line')
            ->set('headline', 'Bookkeeper and payroll specialist')
            ->set('bio', 'Twelve years of small business books.')
            ->set('experienceYears', '12')
            ->set('kind', 'professional')
            ->call('addTag', 'skills', 'Payroll')
            ->call('addTag', 'interests', 'Cycling')
            ->call('addTag', 'languages', 'isiZulu')
            ->call('addAchievement')->set('achievements.0', 'Cleared a three year backlog')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $p = $this->profileOf($u);
        $this->assertSame('Bookkeeper and payroll specialist', $p->headline);
        $this->assertSame(12, $p->experience_years);
        $this->assertSame('professional', $p->kind);
        $this->assertContains('Payroll', $p->skills);
        $this->assertSame(['Cycling'], $p->interests);
        $this->assertSame(['isiZulu'], $p->languages);
        $this->assertSame(['Cleared a three year backlog'], $p->achievements);
    }

    public function test_the_headline_is_limited_and_years_are_validated(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(ProfileEditor::class)
            ->set('headline', str_repeat('x', 121))->call('save')->assertHasErrors(['headline'])
            ->set('headline', 'Fine')->set('experienceYears', '99')->call('save')->assertHasErrors(['experienceYears']);
    }

    public function test_work_history_stores_the_employer_and_the_hide_flag_the_matcher_reads(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(ProfileEditor::class)
            ->call('addJob')
            ->set('jobs.0.title', 'Accountant')->set('jobs.0.org', 'Acme Holdings')->set('jobs.0.from_year', '2019')->set('jobs.0.current', true)->set('jobs.0.hide', true)
            ->call('addJob')
            ->set('jobs.1.title', 'Clerk')->set('jobs.1.org', 'Old Co')->set('jobs.1.from_year', '2015')->set('jobs.1.to_year', '2019')
            ->call('save')
            ->assertHasNoErrors();

        $jobs = $this->profileOf($u)->work_history;
        $this->assertCount(2, $jobs);
        $this->assertSame('Acme Holdings', $jobs[0]['employer']);
        $this->assertTrue($jobs[0]['hide_from_employer']);
        $this->assertNull($jobs[0]['to_year'], 'a current job has no end year');
        $this->assertFalse($jobs[1]['hide_from_employer']);
        $this->assertSame(2019, $jobs[1]['to_year']);
    }

    public function test_a_hide_flag_without_an_employer_name_is_not_stored(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(ProfileEditor::class)
            ->call('addJob')->set('jobs.0.title', 'Driver')->set('jobs.0.hide', true)->call('save');

        $this->assertFalse($this->profileOf($u)->work_history[0]['hide_from_employer']);
    }

    public function test_job_years_must_run_forwards_and_a_job_needs_a_title(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(ProfileEditor::class)
            ->call('addJob')->set('jobs.0.title', 'Driver')->set('jobs.0.from_year', '2020')->set('jobs.0.to_year', '2018')
            ->call('save')->assertHasErrors(['jobs.0.to_year'])
            ->set('jobs.0.to_year', '2022')->set('jobs.0.title', '')->set('jobs.0.org', 'Somewhere')
            ->call('save')->assertHasErrors(['jobs.0.title']);
    }

    public function test_chips_respect_their_limits_and_can_be_removed(): void
    {
        $u = $this->member(['skills' => null]);
        $c = Livewire::actingAs($u)->test(ProfileEditor::class);

        foreach (range(1, 14) as $i) {
            $c->call('addTag', 'skills', "skill {$i}");
        }
        $this->assertCount(12, $c->get('skills'));

        $c->call('removeTag', 'skills', 0);
        $this->assertCount(11, $c->get('skills'));
        $c->call('addTag', 'nonsense', 'x');
        $c->set('tagQuery.skills', 'book');
        $this->assertNotEmpty($c->instance()->tagSuggestions('skills'));
    }

    public function test_an_avatar_is_stored_through_the_media_service_and_can_be_removed(): void
    {
        Storage::fake('local');
        config(['pulse.entities.media.disk' => 'local']);
        $u = $this->member();

        $c = Livewire::actingAs($u)->test(ProfileEditor::class)
            ->set('avatar', UploadedFile::fake()->image('me.png', 300, 300))
            ->assertSet('avatarError', null);

        $id = $this->profileOf($u)->avatar_media_id;
        $this->assertNotNull($id);
        $this->assertSame('avatar', PulseMedia::query()->findOrFail($id)->purpose);

        $c->call('removeAvatar');
        $this->assertNull($this->profileOf($u)->avatar_media_id);
        $this->assertNull(PulseMedia::query()->find($id), 'the old files go with it');
    }

    public function test_a_non_image_is_refused_with_a_plain_message(): void
    {
        Storage::fake('local');
        $u = $this->member();

        Livewire::actingAs($u)->test(ProfileEditor::class)
            ->set('avatar', UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'))
            ->assertNotSet('avatarError', null);

        $this->assertNull($this->profileOf($u)->avatar_media_id);
    }

    public function test_one_person_cannot_edit_another_persons_profile(): void
    {
        $a = $this->member(['headline' => 'A']);
        $b = $this->member(['headline' => 'B']);

        Livewire::actingAs($b)->test(ProfileEditor::class)->set('headline', 'Hijack')->call('save');

        $this->assertSame('A', $this->profileOf($a)->headline);
        $this->assertSame('Hijack', $this->profileOf($b)->headline);
    }
}

<?php

namespace Tests\Feature\Security;

use App\Livewire\Pulse\ModerationQueue;
use App\Livewire\Pulse\Operator\Dashboard;
use App\Models\PulseProfile;
use App\Services\Pulse\Entities\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** The moderator desk checks who is asking on every request, and links people store can only be web links. */
class StaffAndLinkTest extends TestCase
{
    use RefreshDatabase;
    use SecurityWorld;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->buildWorld();
    }

    public function test_the_desk_and_the_operator_view_are_closed_to_everyone_who_is_not_a_platform_moderator(): void
    {
        foreach (['stranger', 'sellerOwner', 'employerOwner', 'pro', 'minor'] as $who) {
            Livewire::actingAs($this->w($who))->test(Dashboard::class)->assertForbidden();
            Livewire::actingAs($this->w($who))->test(ModerationQueue::class)->assertForbidden();
        }

        Livewire::actingAs($this->w('moderator'))->test(Dashboard::class)->assertOk();
    }

    public function test_a_moderator_who_is_demoted_mid_session_loses_the_desk_on_the_next_action(): void
    {
        $mod = $this->w('moderator');
        $desk = Livewire::actingAs($mod)->test(ModerationQueue::class);
        $dashboard = Livewire::actingAs($mod)->test(Dashboard::class);

        PulseProfile::query()->where('user_id', $mod->id)->update(['role' => 'customer']);
        $mod->unsetRelation('pulseProfile'); // a real request loads the person afresh

        $dashboard->call('setRange', 7)->assertForbidden();
        $desk->call('approveReview', 1)->assertForbidden();
        $this->actingAs($mod->fresh())->get('/operator')->assertForbidden();
        $this->actingAs($mod->fresh())->get('/moderation')->assertForbidden();
    }

    public function test_stored_links_can_only_be_web_links(): void
    {
        $owner = $this->w('employerOwner');
        $employer = $this->w('employer');

        foreach (['javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=', 'vbscript:x', '//evil.example/x', 'ftp://evil.example', 'file:///etc/passwd'] as $url) {
            try {
                app(RoleService::class)->create($owner, $employer, ['title' => 'Link test role '.md5($url), 'summary' => 'Plain words', 'apply_mode' => 'external_url', 'apply_url' => $url]);
                $this->fail("accepted {$url}");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $role = app(RoleService::class)->create($owner, $employer, ['title' => 'A role with a good link', 'summary' => 'Plain words', 'apply_mode' => 'external_url', 'apply_url' => 'https://example.test/apply']);
        $this->assertSame('https://example.test/apply', $role->apply_url);

        // And it is rendered for a signed in person with no opener and no referrer to the other site.
        $role->forceFill(['status' => 'live', 'moderation' => 'approved'])->save();
        $html = $this->actingAs($this->w('stranger'))->get('/b/'.$employer->slug.'/r/'.$role->slug)->getContent();
        $this->assertMatchesRegularExpression('~href="https://example.test/apply"[^>]*rel="[^"]*noopener~', $html);
    }
}

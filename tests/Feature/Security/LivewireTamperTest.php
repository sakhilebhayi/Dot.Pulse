<?php

namespace Tests\Feature\Security;

use App\Livewire\Pulse\Business\EntityForm;
use App\Livewire\Pulse\Business\OfferManager;
use App\Livewire\Pulse\Business\TeamManager;
use App\Livewire\Pulse\KnockSheet;
use App\Livewire\Pulse\Lines\Inbox;
use App\Livewire\Pulse\Lines\Report;
use App\Livewire\Pulse\Lines\Thread;
use App\Livewire\Pulse\Me\NotificationList;
use App\Livewire\Pulse\Need\NeedForm;
use App\Livewire\Pulse\Need\NeedPage;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseMessage;
use App\Models\PulseNeed;
use App\Models\User;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * What a browser can do to a Livewire component: change a public property, call an action with an id it was not given.
 * Identifiers are locked; every action checks again on the server who is asking.
 */
class LivewireTamperTest extends TestCase
{
    use RefreshDatabase;
    use SecurityWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function otherLine(): PulseConversation
    {
        // A line between two other people that the stranger has nothing to do with.
        return $this->openLine($this->w('needOwner'), $this->w('seller'));
    }

    public function test_a_line_id_cannot_be_rewritten_from_the_browser(): void
    {
        $other = $this->otherLine();
        $component = Livewire::actingAs($this->w('stranger'))->test(Thread::class, ['conversation' => $this->w('line')]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('conversationId', $other->id);
    }

    public function test_a_stranger_cannot_open_send_to_or_poll_someone_elses_line(): void
    {
        $other = $this->otherLine();

        Livewire::actingAs($this->w('stranger'))->test(Thread::class, ['conversation' => $other])->assertStatus(404);
        $this->assertSame(0, PulseMessage::query()->where('pulse_conversation_id', $other->id)->where('user_id', $this->w('stranger')->id)->count());
    }

    public function test_answering_actions_refuse_a_line_that_is_not_yours(): void
    {
        $other = $this->otherLine();
        $closed = $this->page($this->person(), ['contact_policy' => 'requests']);
        $closed->forceFill(['moderation' => 'approved'])->save();
        $request = app(LineService::class)->knock($this->w('needOwner'), ['entity' => $closed->id], 'enquiry', $this->card());
        $this->assertSame(PulseConversation::REQUESTED, $request->status);
        $stranger = $this->w('stranger');

        foreach (['accept', 'ignore', 'closeLine', 'chooseDecline', 'askBlock'] as $action) {
            foreach ([$other->id, $request->id] as $id) {
                Livewire::actingAs($stranger)->test(Inbox::class)->call($action, $id)->assertStatus(404);
            }
        }

        $this->assertSame(PulseConversation::OPEN, $other->fresh()->status);
    }

    public function test_the_inbox_entity_filter_cannot_be_pointed_at_a_page_you_do_not_answer_for(): void
    {
        $component = Livewire::actingAs($this->w('stranger'))->test(Inbox::class, ['startTab' => 'all']);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('entityId', $this->w('seller')->id);
    }

    public function test_the_inbox_never_lists_another_pages_lines_even_when_asked_for_one(): void
    {
        $line = $this->w('line');

        // A stranger who is not on the seller page asks the inbox service for that page's lines.
        $page = app(LineService::class)->inbox($this->w('employerOwner'), ['tab' => 'all', 'entity_id' => $this->w('seller')->id]);

        $this->assertSame([], collect($page->items())->pluck('id')->all());
        $this->assertNotContains($line->id, collect($page->items())->pluck('id')->all());
    }

    public function test_report_subject_cannot_be_rewritten_and_a_foreign_message_cannot_be_reported(): void
    {
        $other = $this->otherLine();
        $message = PulseMessage::query()->where('pulse_conversation_id', $other->id)->first();

        $component = Livewire::actingAs($this->w('stranger'))->test(Report::class, ['subjectType' => 'conversation', 'subjectId' => $this->w('line')->id]);
        try {
            $component->set('subjectId', $other->id);
            $this->fail('subjectId must be locked');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertTrue(true);
        }

        Livewire::actingAs($this->w('stranger'))->test(Report::class, ['subjectType' => 'message', 'subjectId' => $message->id])->assertStatus(404);
    }

    public function test_a_report_on_a_line_nobody_gave_you_is_refused_even_by_a_forged_component_call(): void
    {
        $other = $this->otherLine();

        Livewire::actingAs($this->w('stranger'))->test(Report::class, ['subjectType' => 'conversation', 'subjectId' => $other->id])->assertStatus(404);
    }

    public function test_an_entity_form_cannot_be_pointed_at_a_page_that_is_not_yours(): void
    {
        $component = Livewire::actingAs($this->w('stranger'))->test(EntityForm::class);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('entityId', $this->w('seller')->id);
    }

    public function test_the_editing_forms_for_other_pages_are_closed_to_non_members(): void
    {
        Livewire::actingAs($this->w('stranger'))->test(OfferManager::class, ['entity' => $this->w('seller')])->assertForbidden();
        Livewire::actingAs($this->w('sellerResponder'))->test(OfferManager::class, ['entity' => $this->w('seller')])->assertForbidden();
        Livewire::actingAs($this->w('sellerEditor'))->test(TeamManager::class, ['entity' => $this->w('seller')])->assertForbidden();
        Livewire::actingAs($this->w('stranger'))->test(EntityForm::class, ['entity' => $this->w('seller')])->assertForbidden();
    }

    public function test_an_admin_cannot_make_anyone_an_owner_and_an_editor_cannot_manage_people(): void
    {
        $team = Livewire::actingAs($this->w('sellerAdmin'))->test(TeamManager::class, ['entity' => $this->w('seller')]);
        $victim = $this->w('sellerResponder');

        $team->call('changeRole', $victim->id, 'owner');
        $this->assertSame('responder', $this->w('seller')->fresh()->memberRole($victim));

        $team->set('email', $this->w('stranger')->email)->set('role', 'owner')->call('add');
        $this->assertNull($this->w('seller')->fresh()->memberRole($this->w('stranger')));

        // An admin cannot touch the owner either.
        $team->call('remove', $this->w('sellerOwner')->id);
        $this->assertSame('owner', $this->w('seller')->fresh()->memberRole($this->w('sellerOwner')));
    }

    public function test_a_need_cannot_be_edited_or_closed_by_someone_else_through_a_forged_id(): void
    {
        $need = $this->w('need');

        Livewire::actingAs($this->w('stranger'))->test(NeedForm::class, ['need' => $need])->assertForbidden();

        $component = Livewire::actingAs($this->w('stranger'))->test(NeedForm::class);
        try {
            $component->set('needId', $need->id);
            $this->fail('needId must be locked');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertTrue(true);
        }

        Livewire::actingAs($this->w('stranger'))->test(NeedPage::class, ['needId' => $need->id])->call('close', false);
        $this->assertSame('open', $need->fresh()->status);
    }

    public function test_a_need_cannot_be_posted_for_a_page_you_do_not_belong_to(): void
    {
        $user = $this->w('stranger');

        $component = Livewire::actingAs($user)->test(NeedForm::class)
            ->set('title', 'Need a distinctive thing on Friday')
            ->set('details', 'Plain details')
            ->set('entity_id', $this->w('seller')->id)
            ->call('save');

        $this->assertSame(0, PulseNeed::query()->where('user_id', $user->id)->where('entity_id', $this->w('seller')->id)->count());
    }

    public function test_knock_sheet_properties_are_locked_and_a_forged_page_is_refused(): void
    {
        $sheet = Livewire::actingAs($this->w('stranger'))->test(KnockSheet::class, ['target' => ['entity' => $this->w('seller')->id]]);

        foreach (['target' => ['entity' => $this->w('employer')->id], 'kind' => 'invite', 'contextId' => 1] as $prop => $value) {
            try {
                $sheet->set($prop, $value);
                $this->fail("{$prop} must be locked");
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertTrue(true);
            }
        }

        // The pulse-knock event is the one door a browser has; the service decides again at the moment of the knock.
        $forged = Livewire::actingAs($this->w('stranger'))->test(KnockSheet::class, ['bare' => true])
            ->dispatch('pulse-knock', spec: ['target' => ['signal' => $this->w('talentSignal')->id, 'as_entity' => $this->w('employer')->id], 'kind' => 'invite'])
            ->set('what', 'Please let me in')
            ->call('send');

        $forged->assertStatus(403);
        $this->assertSame(1, PulseConversation::query()->where('kind', 'invite')->count()); // only the world's own invite
    }

    public function test_knocking_with_a_page_as_the_sender_needs_the_right_to_answer_for_it(): void
    {
        // A responder of the seller page is not an employer; an editor cannot answer for any page.
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(LineService::class)->knock($this->w('sellerEditor'), ['signal' => $this->w('talentSignal')->id, 'as_entity' => $this->w('seller')->id], 'invite', $this->card());
    }

    public function test_notifications_can_only_be_read_and_opened_by_their_owner(): void
    {
        $owner = $this->w('sellerOwner');
        $owner->notify(new \Illuminate\Notifications\Messages\SimpleMessage ? new class extends \Illuminate\Notifications\Notification {
            public function via($n): array { return ['database']; }
            public function toArray($n): array { return ['title' => 'Mine', 'category' => 'lines', 'url' => '/floor']; }
        } : null);
        $id = $owner->notifications()->first()->id;

        Livewire::actingAs($this->w('stranger'))->test(NotificationList::class)->call('markRead', $id)->call('open', $id);

        $this->assertNull($owner->fresh()->notifications()->first()->read_at);
    }
}

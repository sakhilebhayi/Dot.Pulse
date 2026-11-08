<?php

namespace Tests\Feature\Business;

use App\Livewire\Pulse\Need\NeedForm;
use App\Livewire\Pulse\Need\NeedPage;
use App\Models\PulseConversation;
use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseSignal;
use App\Services\Pulse\Lines\LineService;
use Livewire\Livewire;

class NeedTest extends BusinessTestCase
{
    public function test_post_a_need_with_tags_and_a_place(): void
    {
        $user = $this->infodotPerson();
        $place = $this->place();

        Livewire::actingAs($user)->test(NeedForm::class)
            ->set('kind', 'supplier')->set('title', 'A bookkeeper for a small shop')
            ->set('details', 'Monthly books and VAT for a spaza shop.')
            ->call('addTag', 'Bookkeeping')->set('tag_query', 'vat')->call('addTag')
            ->set('budget_text', 'Up to R3 000')->set('needed_by', now()->addDays(10)->toDateString())
            ->set('reach', 'local')->call('choosePlace', $place->id)->set('visibility', 'matched')
            ->call('save')->assertHasNoErrors();

        $need = PulseNeed::where('title', 'A bookkeeper for a small shop')->firstOrFail();
        $this->assertSame('open', $need->status);
        $this->assertSame('approved', $need->moderation);
        $this->assertSame(['Bookkeeping', 'vat'], $need->tags);
        $this->assertSame('matched', $need->visibility);
        $this->assertSame($place->id, $need->place_id);
        $this->assertSame('supplier', $need->kind);
    }

    public function test_title_is_required_and_a_local_need_needs_a_place(): void
    {
        $user = $this->person();

        Livewire::actingAs($user)->test(NeedForm::class)->set('title', '')->set('reach', 'national')->call('save')->assertHasErrors(['title']);
        Livewire::actingAs($user)->test(NeedForm::class)->set('title', 'Needs a place')->set('reach', 'local')->call('clearPlace')->call('save')->assertHasErrors(['place_query']);
        Livewire::actingAs($user)->test(NeedForm::class)->set('title', 'Past date')->set('reach', 'national')->set('needed_by', now()->subDay()->toDateString())->call('save')->assertHasErrors(['needed_by']);
    }

    public function test_the_open_needs_quota_is_explained_and_enforced(): void
    {
        config(['pulse.entities.max_open_needs' => 1]);
        $user = $this->infodotPerson();
        PulseNeed::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)->test(NeedForm::class)
            ->assertSee('the most at one time')
            ->set('title', 'One more need')->set('reach', 'national')
            ->call('save')->assertHasErrors(['title']);

        $this->assertDatabaseMissing('pulse_needs', ['title' => 'One more need']);
    }

    public function test_a_new_author_is_waiting_for_a_human(): void
    {
        Livewire::actingAs($this->person())->test(NeedForm::class)
            ->set('title', 'Fresh account need')->set('reach', 'national')->call('save');

        $need = PulseNeed::where('title', 'Fresh account need')->firstOrFail();
        $this->assertSame('pending', $need->moderation);

        Livewire::actingAs($need->user)->test(NeedPage::class, ['needId' => $need->id])->assertSee('Waiting for a human');
    }

    public function test_only_the_owner_can_edit_and_edit_saves(): void
    {
        $owner = $this->infodotPerson();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id, 'title' => 'Old title']);

        Livewire::actingAs($this->person())->test(NeedForm::class, ['need' => $need])->assertForbidden();

        Livewire::actingAs($owner)->test(NeedForm::class, ['need' => $need])
            ->assertSet('title', 'Old title')->set('title', 'New title')->set('reach', 'national')->call('save')->assertHasNoErrors();
        $this->assertSame('New title', $need->fresh()->title);
    }

    public function test_owner_closes_fulfils_reopens_and_deletes(): void
    {
        $owner = $this->infodotPerson();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id]);

        $c = Livewire::actingAs($owner)->test(NeedPage::class, ['needId' => $need->id]);

        $c->call('close')->assertSee('Closed');
        $this->assertSame('closed', $need->fresh()->status);

        $c->call('reopen')->assertSee('Open again');
        $this->assertSame('open', $need->fresh()->status);

        $c->call('fulfil')->assertSee('fulfilled');
        $this->assertSame('fulfilled', $need->fresh()->status);

        $c->call('delete')->assertRedirect();
        $this->assertSoftDeleted('pulse_needs', ['id' => $need->id]);
    }

    public function test_a_stranger_cannot_close_or_delete_someone_elses_need(): void
    {
        $owner = $this->infodotPerson();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id]);
        $stranger = $this->person();

        $c = Livewire::actingAs($stranger)->test(NeedPage::class, ['needId' => $need->id]);
        $c->call('close');
        $c->call('delete');

        $this->assertSame('open', $need->fresh()->status);
        $this->assertNull($need->fresh()->deleted_at);
    }

    public function test_others_see_a_beat_like_page_with_knock_and_none_of_the_owner_controls(): void
    {
        $owner = $this->infodotPerson();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id, 'title' => 'Looking for a plumber', 'kind' => 'collab']);

        $this->actingAs($this->person())->get(route('needs.show', ['need' => $need->id]))
            ->assertOk()->assertSee('Looking for a plumber')->assertSee('Offer to collaborate')
            ->assertSeeLivewire('pulse.knock-sheet')
            ->assertDontSee('Who it was shown to')->assertDontSee('Delete');
    }

    public function test_the_owner_of_an_open_need_can_ask_up_to_three_businesses_for_quotes_from_the_need_page(): void
    {
        $owner = $this->infodotPerson();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id, 'status' => 'open', 'moderation' => 'approved']);

        $this->actingAs($owner)->get(route('needs.show', ['need' => $need->id]))
            ->assertOk()->assertSeeLivewire('pulse.lines.quote-request')->assertSee('Ask for quotes');

        // Other people never get the owner's request-a-quote control.
        $this->actingAs($this->person())->get(route('needs.show', ['need' => $need->id]))
            ->assertOk()->assertDontSeeLivewire('pulse.lines.quote-request');
    }

    public function test_a_matched_need_is_only_open_to_matched_people(): void
    {
        $owner = $this->infodotPerson();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id, 'visibility' => 'matched']);
        $signal = PulseSignal::where('subject_type', 'need')->where('subject_id', $need->id)->firstOrFail();
        $stranger = $this->person();
        $matched = $this->person();
        PulseMatch::create(['user_id' => $matched->id, 'signal_id' => $signal->id, 'score' => 50, 'reasons' => [['code' => 'topic', 'text' => 'x']], 'computed_at' => now()]);

        $this->get(route('needs.show', ['need' => $need->id]))->assertRedirect();
        $this->actingAs($stranger)->get(route('needs.show', ['need' => $need->id]))->assertNotFound();
        $this->actingAs($matched)->get(route('needs.show', ['need' => $need->id]))->assertOk()->assertSee($need->title);
        $this->actingAs($owner)->get(route('needs.show', ['need' => $need->id]))->assertOk();
    }

    public function test_the_owner_sees_counts_only_and_lines_it_started(): void
    {
        $owner = $this->infodotPerson();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id]);
        $signal = PulseSignal::where('subject_type', 'need')->where('subject_id', $need->id)->firstOrFail();
        $a = $this->person(['name' => 'Aphiwe Secret']);
        $b = $this->person(['name' => 'Bongani Secret']);
        foreach ([$a, $b] as $i => $u) {
            PulseMatch::create(['user_id' => $u->id, 'signal_id' => $signal->id, 'score' => 50, 'reasons' => [['code' => 'topic', 'text' => 'x']], 'computed_at' => now(), 'seen_at' => $i === 0 ? now() : null]);
        }

        $html = Livewire::actingAs($owner)->test(NeedPage::class, ['needId' => $need->id])
            ->assertSee('Who it was shown to')->assertSee('People it fits')->assertSee('Have seen it')
            ->assertSee('never who')->html();

        $this->assertStringNotContainsString('Aphiwe', $html);
        $this->assertStringNotContainsString('Bongani', $html);
    }

    public function test_a_need_with_no_matches_does_not_show_zero_counts(): void
    {
        $owner = $this->infodotPerson();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id]);

        Livewire::actingAs($owner)->test(NeedPage::class, ['needId' => $need->id])->assertSee('Not shown to anyone yet')->assertDontSee('People it fits');
    }

    public function test_lines_started_from_a_need_are_listed_for_the_owner(): void
    {
        $owner = $this->infodotPerson();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id]);
        $helper = $this->person(['name' => 'Helpful Hanna']);

        app(LineService::class)->knock($helper, ['user' => $owner->id], 'enquiry', ['what' => 'I can help with this'], $need);
        $this->assertSame(1, PulseConversation::where('context_type', 'need')->where('context_id', $need->id)->count());

        Livewire::actingAs($owner)->test(NeedPage::class, ['needId' => $need->id])->assertSee('Lines this started')->assertSee('Helpful Hanna');
    }

    public function test_the_create_and_edit_routes(): void
    {
        $owner = $this->person();
        $other = $this->person();
        $need = PulseNeed::factory()->create(['user_id' => $owner->id]);

        $this->get(route('needs.create'))->assertRedirect();
        $this->actingAs($owner)->get(route('needs.create'))->assertOk()->assertSeeLivewire(NeedForm::class);
        $this->actingAs($owner)->get(route('needs.edit', ['need' => $need->id]))->assertOk();
        $this->actingAs($other)->get(route('needs.edit', ['need' => $need->id]))->assertNotFound();
    }
}
